<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\ShippingRateProvider;
use App\Contracts\WarehouseStockProvider;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SelectedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.mode' => 'manual', 'checkout.free_shipping' => true, 'checkout.manual.shipping_fee' => 25000]);
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $shipping = Mockery::mock(ShippingRateProvider::class);
        $shipping->shouldNotReceive('quote');
        $this->app->instance(ShippingRateProvider::class, $shipping);
    }

    private function address(): array
    {
        return ['name' => 'Pembeli Pilihan', 'phone' => '628123456789', 'address' => 'Jalan Pengujian 1',
            'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40135'];
    }

    private function addProduct(int $price = 15000): array
    {
        $variant = ProductVariant::factory()->create(['price' => $price]);
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        $response = $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 2,
        ])->assertOk();
        $item = collect($response->json('data.items'))->firstWhere('listing_id', $listing->id);

        return [$item['id'], $listing, $variant];
    }

    private function addBundle(): int
    {
        $variant = ProductVariant::factory()->create();
        $bundle = ProductBundle::factory()->create(['price' => 40000]);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $response = $this->postJson('/api/v1/cart/items', [
            'type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1,
        ])->assertOk();

        return collect($response->json('data.items'))->firstWhere('bundle_listing_id', $listing->id)['id'];
    }

    public function test_selected_product_and_bundle_are_ordered_with_free_shipping_and_other_items_remain(): void
    {
        [$productId] = $this->addProduct();
        $bundleId = $this->addBundle();
        [$remainingId, $remainingListing] = $this->addProduct(90000);
        $remainingListing->update(['status' => 'inactive']);
        $payload = ['cart_item_ids' => [$productId, $bundleId], 'shipping_address' => $this->address(), 'shipping_fee' => 99999];

        $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.subtotal', '70000.00')
            ->assertJsonPath('data.shipping.service', 'FREE')->assertJsonPath('data.shipping.gross_fee', '0.00')
            ->assertJsonPath('data.shipping.subsidy', '0.00')->assertJsonPath('data.shipping.fee', '0.00')
            ->assertJsonPath('data.shipping.subsidy_rule_id', null)->assertJsonPath('data.total', '70000.00');
        $this->assertDatabaseCount('cart_items', 3);

        $response = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'selected-mixed'])->assertCreated()
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.subtotal', '70000.00')
            ->assertJsonPath('data.shipping_gross_fee', '0.00')->assertJsonPath('data.shipping_subsidy', '0.00')
            ->assertJsonPath('data.shipping_fee', '0.00')->assertJsonPath('data.shipping_service', 'FREE')
            ->assertJsonPath('data.total', '70000.00')->assertJsonPath('data.status', 'pending_payment');
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['id' => $remainingId]);
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertEqualsCanonicalizing(['product', 'bundle'], $order->items->pluck('item_type')->all());

        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'selected-mixed'])->assertOk()
            ->assertJsonPath('data.id', $order->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_product_only_checkout_keeps_unselected_bundle(): void
    {
        [$productId] = $this->addProduct();
        $bundleId = $this->addBundle();
        $payload = ['cart_item_ids' => [$productId], 'shipping_address' => $this->address()];
        $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.cart_item_id', $productId)
            ->assertJsonPath('data.total', '30000.00');
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'product-only'])->assertCreated()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.type', 'product');
        $this->assertDatabaseHas('cart_items', ['id' => $bundleId]);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_bundle_only_checkout_keeps_unselected_product(): void
    {
        [$productId] = $this->addProduct();
        $bundleId = $this->addBundle();
        $payload = ['cart_item_ids' => [$bundleId], 'shipping_address' => $this->address()];
        $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()
            ->assertJsonPath('data.items.0.cart_item_id', $bundleId)->assertJsonPath('data.total', '40000.00');
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'bundle-only'])->assertCreated()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.type', 'bundle');
        $this->assertDatabaseHas('cart_items', ['id' => $productId]);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_invalid_foreign_missing_and_duplicate_selections_are_rejected_without_orders(): void
    {
        [$id, $listing, $variant] = $this->addProduct();
        $foreignCart = Cart::query()->create(['user_id' => User::factory()->create()->id]);
        $foreignItem = $foreignCart->items()->create([
            'item_type' => 'product', 'store_product_listing_id' => $listing->id,
            'product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => $variant->price,
        ]);
        $cases = [[], null, 'invalid', [0], [$id, $id], ['invalid'], ['a' => $id], [$id, $foreignItem->id], [$id, 999999]];
        foreach ($cases as $selection) {
            $payload = ['cart_item_ids' => $selection, 'shipping_address' => $this->address()];
            foreach (['/api/v1/checkout/preview', '/api/v1/orders'] as $endpoint) {
                $this->postJson($endpoint, $payload, ['Idempotency-Key' => 'invalid-selection'])
                    ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
            }
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_unavailable_selected_item_is_rejected_but_not_removed(): void
    {
        [$id, $listing] = $this->addProduct();
        $listing->update(['status' => 'inactive']);
        $payload = ['cart_item_ids' => [$id], 'shipping_address' => $this->address()];
        $this->postJson('/api/v1/checkout/preview', $payload)->assertStatus(409);
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'stale-selection'])->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_omitting_selection_still_checks_out_whole_cart_with_free_shipping(): void
    {
        $this->addProduct();
        $this->addBundle();
        $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()], ['Idempotency-Key' => 'whole-cart'])
            ->assertCreated()->assertJsonCount(2, 'data.items')->assertJsonPath('data.total', '70000.00')
            ->assertJsonPath('data.shipping_fee', '0.00');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_integrated_checkout_uses_only_selected_stock_and_does_not_request_shipping_rates(): void
    {
        config(['checkout.mode' => 'integrated']);
        [$id, , $variant] = $this->addProduct();
        $this->addBundle();
        $items = [['sku' => $variant->sku, 'quantity' => 2]];
        $warehouse = Mockery::mock(WarehouseStockProvider::class);
        $warehouse->shouldReceive('checkAvailability')->twice()->with($items)->andReturn(['available' => true, 'items' => []]);
        $warehouse->shouldReceive('reserve')->once()->with($items, Mockery::type('string'))->andReturn('selected-reservation');
        $this->app->instance(WarehouseStockProvider::class, $warehouse);
        $payload = ['cart_item_ids' => [$id], 'shipping_address' => $this->address()];
        $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->assertJsonPath('data.shipping.fee', '0.00');
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'integrated-selected'])->assertCreated()
            ->assertJsonPath('data.total', '30000.00')->assertJsonPath('data.shipping_fee', '0.00');
        $this->assertDatabaseCount('cart_items', 1);
    }
}
