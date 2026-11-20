<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\WarehouseStockProvider;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class NearestWarehouseCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private array $quantities = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'checkout.mode' => 'manual', 'checkout.free_shipping' => true,
            'checkout.stock_mode' => 'check_only', 'checkout.warehouse_selection' => 'nearest_available',
            'services.warehouse_remaining_stock.enabled' => true,
            'services.warehouse_remaining_stock.base_url' => 'https://stock.example.test',
            'services.warehouse_remaining_stock.path' => '/index.php?procs=gudang.stok.sisa',
        ]);
        Http::preventStrayRequests();
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer, ['customer-api']);
        $provider = Mockery::mock(WarehouseStockProvider::class);
        foreach (['checkAvailability', 'reserve', 'release', 'confirm'] as $method) {
            $provider->shouldNotReceive($method);
        }
        $this->app->instance(WarehouseStockProvider::class, $provider);
        foreach (['NEAR' => 107.6, 'FAR' => 108.2] as $code => $longitude) {
            Warehouse::create([
                'code' => $code, 'realname' => $code, 'codename' => $code, 'initial' => $code,
                'area_group' => 'pulaujawa', 'latitude' => -6.9, 'longitude' => $longitude,
                'coordinates' => '-6.9, '.$longitude, 'synced_at' => now(),
            ]);
        }
    }

    private function payload(): array
    {
        return ['shipping_address' => [
            'name' => 'Pembeli Uji', 'phone' => '628123456789', 'address' => 'Jalan Uji',
            'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135',
            'latitude' => -6.9, 'longitude' => 107.6,
        ]];
    }

    private function addProduct(string $code = 'QNC', array $warehouses = ['NEAR', 'FAR'], int $quantity = 2, ?Product $product = null): ProductVariant
    {
        $product ??= Product::factory()->create(['product_code' => $code]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Varian '.($product->variants()->count() + 1)]);
        foreach ($warehouses as $warehouse) {
            $variant->warehouseAssignments()->create(['warehouse_code' => $warehouse]);
        }
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);
        $this->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => $quantity])->assertOk();

        return $variant;
    }

    private function stock(array $quantities): void
    {
        $this->quantities = $quantities;
        Http::fake(function ($request) {
            $form = collect($request->data())->pluck('contents', 'name')->all();

            return Http::response(['error' => '', 'data' => ['jumlah' => $this->quantities[$form['code']][$form['produk']] ?? 0]]);
        });
    }

    private function preview(array $payload = [])
    {
        return $this->postJson('/api/v1/checkout/preview', $payload ?: $this->payload());
    }

    private function place(string $token, array $extra = [], string $key = 'nearest-order')
    {
        return $this->postJson('/api/v1/orders', [...$this->payload(), 'checkout_token' => $token, ...$extra], ['Idempotency-Key' => $key]);
    }

    public function test_nearest_sufficient_warehouse_is_selected_and_stock_is_checked_again_for_order(): void
    {
        $this->addProduct();
        config(['checkout.preferred_warehouse_code' => 'FAR']);
        $this->stock(['NEAR' => ['QNC' => 2], 'FAR' => ['QNC' => 100]]);
        $preview = $this->preview()->assertOk()->assertJsonPath('data.shipping_origin.warehouse_code', 'NEAR')
            ->assertJsonPath('data.shipping_origin.distance_km', 0)->assertJsonPath('data.stock_mode', 'check_only')
            ->assertJsonPath('data.stock_check.reserved', false)->assertJsonPath('data.stock_check.items.0.available_quantity', 2);
        $order = $this->place($preview->json('data.checkout_token'))->assertCreated()
            ->assertJsonPath('data.shipping_origin.warehouse_code', 'NEAR')->assertJsonPath('data.stock_check.reserved', false);
        Http::assertSentCount(2);
        $this->assertNull(Order::findOrFail($order->json('data.id'))->stock_reservation_id);
        $this->assertDatabaseCount('cart_items', 0);
        $this->getJson('/api/v1/orders/'.$order->json('data.id'))->assertOk()->assertJsonPath('data.stock_check.items.0.requested_quantity', 2);
    }

    public function test_nearest_warehouse_is_selected_by_distance_without_calling_stock_api_in_unlimited_mode(): void
    {
        $this->addProduct();
        config(['checkout.stock_mode' => 'unlimited']);

        $preview = $this->preview()->assertOk()
            ->assertJsonPath('data.shipping_origin.warehouse_code', 'NEAR')
            ->assertJsonPath('data.shipping_origin.distance_km', 0)
            ->assertJsonPath('data.shipping_origin.selection_method', 'nearest_by_distance')
            ->assertJsonPath('data.stock_mode', 'unlimited')
            ->assertJsonPath('data.stock_check', null);

        $order = $this->place($preview->json('data.checkout_token'))->assertCreated()
            ->assertJsonPath('data.shipping_origin.warehouse_code', 'NEAR')
            ->assertJsonPath('data.stock_mode', 'unlimited')
            ->assertJsonPath('data.stock_check', null);

        Http::assertNothingSent();
        $this->assertNull(Order::findOrFail($order->json('data.id'))->stock_check);
    }

    public function test_shortage_falls_back_to_next_nearest_warehouse(): void
    {
        $this->addProduct();
        $this->stock(['NEAR' => ['QNC' => 1], 'FAR' => ['QNC' => 5]]);
        $this->preview()->assertOk()->assertJsonPath('data.shipping_origin.warehouse_code', 'FAR');
        Http::assertSentCount(2);
    }

    public function test_variants_and_bundle_components_share_one_stock_pool_per_product(): void
    {
        $variant = $this->addProduct(quantity: 2);
        $this->addProduct(quantity: 3, product: $variant->product);
        $bundle = ProductBundle::factory()->create();
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 3]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 2])->assertOk();
        $this->stock(['NEAR' => ['QNC' => 10], 'FAR' => ['QNC' => 11]]);
        $this->preview()->assertOk()->assertJsonPath('data.shipping_origin.warehouse_code', 'FAR')
            ->assertJsonPath('data.stock_check.items.0.requested_quantity', 11)->assertJsonCount(1, 'data.stock_check.items');
        Http::assertSentCount(2);
    }

    public function test_no_order_is_split_when_different_products_only_have_stock_in_different_warehouses(): void
    {
        $this->addProduct('QNC');
        $this->addProduct('GARLIC');
        $this->stock(['NEAR' => ['QNC' => 20, 'GARLIC' => 0], 'FAR' => ['QNC' => 0, 'GARLIC' => 20]]);
        $this->preview()->assertStatus(409)->assertJsonPath('code', 'STOCK_UNAVAILABLE');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_unassigned_inactive_and_invalid_coordinate_warehouses_are_not_candidates(): void
    {
        $this->addProduct(warehouses: ['NEAR', 'FAR']);
        Warehouse::where('code', 'NEAR')->update(['is_active' => false]);
        Warehouse::where('code', 'FAR')->update(['latitude' => null]);
        Warehouse::create(['code' => 'UNASSIGNED', 'realname' => 'Unassigned', 'codename' => 'X', 'initial' => 'X', 'area_group' => 'pulaujawa',
            'latitude' => -6.9, 'longitude' => 107.6, 'synced_at' => now()]);
        $this->preview()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_NOT_AVAILABLE');
        Http::assertNothingSent();
    }

    public function test_missing_coordinates_require_a_confirmed_shipping_address(): void
    {
        $this->addProduct();
        $payload = $this->payload();
        unset($payload['shipping_address']['latitude']);
        $this->preview($payload)->assertUnprocessable()->assertJsonValidationErrors('shipping_address.latitude');
        Http::assertNothingSent();
    }

    public function test_saved_address_coordinates_are_used_and_other_customers_address_is_rejected(): void
    {
        $this->addProduct();
        $address = Address::create([...$this->payload()['shipping_address'], 'label' => 'Alamat uji', 'user_id' => $this->customer->id, 'longitude' => 108.2]);
        $this->stock(['FAR' => ['QNC' => 2]]);
        $this->preview(['address_id' => $address->id])->assertOk()->assertJsonPath('data.shipping_origin.warehouse_code', 'FAR');
        $address->update(['user_id' => User::factory()->create()->id]);
        $this->preview(['address_id' => $address->id])->assertNotFound();
    }

    public function test_provider_error_does_not_skip_an_unknown_nearest_warehouse(): void
    {
        $this->addProduct();
        Http::fake(['*' => Http::response(['error' => 'upstream unavailable', 'data' => ['jumlah' => 0]])]);
        $this->preview()->assertStatus(503)->assertJsonPath('code', 'WAREHOUSE_UNAVAILABLE');
        Http::assertSentCount(1);
    }

    public function test_changed_warehouse_after_preview_requires_confirmation_and_keeps_cart(): void
    {
        $this->addProduct();
        $this->stock(['NEAR' => ['QNC' => 10], 'FAR' => ['QNC' => 10]]);
        $token = $this->preview()->assertOk()->json('data.checkout_token');
        $this->quantities['NEAR']['QNC'] = 0;
        $this->place($token)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_stock_drop_without_alternative_blocks_order_and_keeps_cart(): void
    {
        $this->addProduct();
        $this->stock(['NEAR' => ['QNC' => 10]]);
        $token = $this->preview()->assertOk()->json('data.checkout_token');
        $this->quantities = [];
        $this->place($token)->assertStatus(409)->assertJsonPath('code', 'STOCK_UNAVAILABLE');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_changed_bundle_requirements_invalidate_preview_even_when_price_is_unchanged(): void
    {
        $variant = $this->addProduct();
        $bundle = ProductBundle::factory()->create();
        $component = ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $this->stock(['NEAR' => ['QNC' => 50]]);
        $token = $this->preview()->assertOk()->json('data.checkout_token');
        $component->update(['quantity' => 2]);
        $this->place($token)->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
    }

    public function test_successful_idempotent_retry_preserves_snapshot_without_checking_stock_again(): void
    {
        $this->addProduct();
        $this->stock(['NEAR' => ['QNC' => 10]]);
        $token = $this->preview()->assertOk()->json('data.checkout_token');
        $created = $this->place($token)->assertCreated();
        Warehouse::query()->update(['is_active' => false]);
        $this->quantities = [];
        $this->place($token)->assertOk()->assertJsonPath('data.id', $created->json('data.id'))
            ->assertJsonPath('data.stock_check', $created->json('data.stock_check'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_only_selected_cart_items_need_stock_and_assignments(): void
    {
        $selected = $this->addProduct();
        $this->addProduct('GARLIC', []);
        $this->stock(['NEAR' => ['QNC' => 2]]);
        $item = CartItem::where('product_variant_id', $selected->id)->sole();
        $this->preview([...$this->payload(), 'cart_item_ids' => [$item->id]])->assertOk()->assertJsonCount(1, 'data.stock_check.items');
    }

    public function test_paid_shipping_or_mismatched_stock_mode_cannot_silently_bypass_validation(): void
    {
        $this->addProduct();
        config(['checkout.free_shipping' => false]);
        $this->preview()->assertStatus(503);
        config(['checkout.free_shipping' => true, 'checkout.stock_mode' => 'external']);
        $this->preview()->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_equal_distances_use_warehouse_code_as_deterministic_tiebreaker(): void
    {
        $this->addProduct();
        Warehouse::where('code', 'FAR')->update(['longitude' => 107.6]);
        $this->stock(['FAR' => ['QNC' => 5], 'NEAR' => ['QNC' => 5]]);
        $this->preview()->assertOk()->assertJsonPath('data.shipping_origin.warehouse_code', 'FAR');
        Http::assertSentCount(1);
    }

    public function test_unlisted_nearest_warehouse_is_skipped_and_unassigned_variant_is_rejected(): void
    {
        $variant = $this->addProduct();
        Warehouse::where('code', 'NEAR')->update(['is_listed' => false]);
        $this->stock(['FAR' => ['QNC' => 5]]);
        $this->preview()->assertOk()->assertJsonPath('data.shipping_origin.warehouse_code', 'FAR');
        $variant->warehouseAssignments()->delete();
        $this->preview()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_NOT_ASSIGNED');
        Http::assertSentCount(1);
    }

    public function test_order_cancellation_does_not_release_nonexistent_reservation(): void
    {
        $this->addProduct();
        $this->stock(['NEAR' => ['QNC' => 5]]);
        $token = $this->preview()->assertOk()->json('data.checkout_token');
        $order = $this->place($token)->assertCreated();
        $this->postJson('/api/v1/orders/'.$order->json('data.id').'/cancel')->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.stock_check.reserved', false);
        Http::assertSentCount(2);
    }

    public function test_overall_stock_deadline_is_enforced_even_when_an_upstream_reply_eventually_arrives(): void
    {
        $this->addProduct();
        config(['checkout.warehouse_selection_timeout' => 0.01]);
        Http::fake(function () {
            usleep(30000);

            return Http::response(['error' => '', 'data' => ['jumlah' => 10]]);
        });
        $this->preview()->assertStatus(503)->assertJsonPath('code', 'WAREHOUSE_UNAVAILABLE');
    }
}
