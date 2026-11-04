<?php

namespace Tests\Feature\Api\V1;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.mode' => 'manual', 'checkout.free_shipping' => true]);
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
    }

    private function payload(array $ids): array
    {
        return ['cart_item_ids' => $ids, 'shipping_address' => [
            'name' => 'Pembeli Harga', 'phone' => '628123456789', 'address' => 'Jalan Pengujian 1',
            'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40135',
        ]];
    }

    private function addProduct(int $price = 15000): array
    {
        $variant = ProductVariant::factory()->create(['price' => $price]);
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 2,
        ])->assertOk();

        return [CartItem::query()->where('store_product_listing_id', $listing->id)->sole(), $variant];
    }

    private function addBundle(): array
    {
        $component = ProductVariant::factory()->create();
        $bundle = ProductBundle::factory()->create(['price' => 40000]);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $component->id, 'quantity' => 2]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1])->assertOk();

        return [CartItem::query()->where('store_bundle_listing_id', $listing->id)->sole(), $bundle, $component];
    }

    public function test_preview_uses_current_product_and_bundle_prices_without_updating_cart(): void
    {
        [$productItem, $variant] = $this->addProduct();
        [$bundleItem, $bundle] = $this->addBundle();
        $variant->update(['price' => 20000]);
        $bundle->update(['price' => 35000]);
        $payload = $this->payload([$productItem->id, $bundleItem->id]);
        $preview = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()
            ->assertJsonPath('data.price_changed', true)->assertJsonCount(2, 'data.price_changes')
            ->assertJsonPath('data.subtotal', '75000.00')->assertJsonPath('data.total', '75000.00')
            ->assertJsonPath('data.shipping.fee', '0.00');
        $this->assertSame('15000.00', $productItem->fresh()->unit_price);
        $this->assertSame('40000.00', $bundleItem->fresh()->unit_price);
        $token = $preview->json('data.checkout_token');
        $this->assertNotEmpty($token);
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'reviewed-prices'])
            ->assertCreated()->assertJsonPath('data.total', '75000.00')
            ->assertJsonPath('data.items.0.unit_price', '20000.00')->assertJsonPath('data.items.1.unit_price', '35000.00');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_unreviewed_price_changes_reject_repeated_order_attempts(): void
    {
        [$item, $variant] = $this->addProduct();
        $variant->update(['price' => 17000]);
        $payload = $this->payload([$item->id]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'no-confirmation'])
                ->assertStatus(409)->assertJsonPath('code', 'PRICE_CHANGED')
                ->assertJsonPath('data.price_changes.0.previous_unit_price', '15000.00')
                ->assertJsonPath('data.price_changes.0.current_unit_price', '17000.00');
        }
        $this->assertSame('15000.00', $item->fresh()->unit_price);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_price_change_after_preview_requires_another_preview(): void
    {
        [$item, $variant] = $this->addProduct();
        $payload = $this->payload([$item->id]);
        $token = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        $variant->update(['price' => 18000]);
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'price-after-preview'])
            ->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $newToken = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $newToken], ['Idempotency-Key' => 'price-after-preview'])
            ->assertCreated()->assertJsonPath('data.total', '36000.00');
    }

    public function test_quantity_and_selection_changes_invalidate_preview(): void
    {
        [$item] = $this->addProduct();
        [$other] = $this->addProduct();
        $payload = $this->payload([$item->id]);
        $token = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        $this->patchJson('/api/v1/cart/items/'.$item->id, ['quantity' => 3])->assertOk();
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'quantity-change'])
            ->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $this->postJson('/api/v1/orders', [...$this->payload([$other->id]), 'checkout_token' => $token], ['Idempotency-Key' => 'selection-change'])
            ->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_unselected_price_changes_do_not_affect_checkout_or_reprice_remaining_cart(): void
    {
        [$item] = $this->addProduct();
        [$other, $otherVariant] = $this->addProduct();
        $payload = $this->payload([$item->id]);
        $token = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        $otherVariant->update(['price' => 99000]);
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'unselected-price'])
            ->assertCreated()->assertJsonPath('data.total', '30000.00');
        $this->assertSame('15000.00', $other->fresh()->unit_price);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_component_retail_price_does_not_change_fixed_bundle_price(): void
    {
        [$item, , $component] = $this->addBundle();
        $component->update(['price' => 99999]);
        $this->postJson('/api/v1/checkout/preview', $this->payload([$item->id]))->assertOk()
            ->assertJsonPath('data.price_changed', false)->assertJsonPath('data.total', '40000.00');
    }

    public function test_expired_or_tampered_tokens_do_not_create_orders(): void
    {
        [$item] = $this->addProduct();
        $payload = $this->payload([$item->id]);
        $token = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => 'invalid-token'], ['Idempotency-Key' => 'tampered-token'])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'expired-token'])
            ->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_EXPIRED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_token_is_bound_to_customer_and_retries_return_original_order_snapshot(): void
    {
        [$item, $variant] = $this->addProduct();
        $owner = auth()->user();
        $payload = $this->payload([$item->id]);
        $token = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        [$foreignItem] = $this->addProduct();
        $this->postJson('/api/v1/orders', [...$this->payload([$foreignItem->id]), 'checkout_token' => $token], ['Idempotency-Key' => 'foreign-token'])
            ->assertUnprocessable();
        Sanctum::actingAs($owner, ['customer-api']);
        $orderId = $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'reviewed-retry'])
            ->assertCreated()->json('data.id');
        $variant->update(['price' => 99000]);
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/orders', [...$payload, 'checkout_token' => $token], ['Idempotency-Key' => 'reviewed-retry'])
            ->assertOk()->assertJsonPath('data.id', $orderId)->assertJsonPath('data.total', '30000.00');
        $this->assertSame(1, Order::query()->where('user_id', $owner->id)->count());
    }
}
