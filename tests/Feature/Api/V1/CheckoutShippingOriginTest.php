<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\WarehouseDirectoryProvider;
use App\Contracts\WarehouseStockProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Services\Marketplace\ManualOrderService;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Concerns\WithCheckoutToken;
use Tests\Concerns\WithCheckoutWarehouse;
use Tests\TestCase;

class CheckoutShippingOriginTest extends TestCase
{
    use RefreshDatabase, WithCheckoutToken, WithCheckoutWarehouse;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.mode' => 'manual', 'checkout.free_shipping' => true, 'checkout.stock_mode' => 'unlimited']);
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer, ['customer-api']);
        $stock = Mockery::mock(WarehouseStockProvider::class);
        foreach (['checkAvailability', 'reserve', 'confirm', 'release'] as $method) {
            $stock->shouldNotReceive($method);
        }
        $this->app->instance(WarehouseStockProvider::class, $stock);
    }

    private function payload(array $extra = []): array
    {
        return [...['shipping_address' => ['name' => 'Pembeli Gudang', 'phone' => '628123456789',
            'address' => 'Jalan Pembeli', 'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135']], ...$extra];
    }

    private function variant(array $codes = ['WH-TASIK']): ProductVariant
    {
        $variant = ProductVariant::factory()->create(['price' => 15000]);
        foreach ($codes as $code) {
            $variant->warehouseAssignments()->create(['warehouse_code' => $code]);
        }

        return $variant;
    }

    private function addProduct(array $codes = ['WH-TASIK']): array
    {
        $variant = $this->variant($codes);
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        $this->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 2])->assertOk();

        return [$variant, CartItem::where('product_variant_id', $variant->id)->sole()];
    }

    private function addBundle(ProductVariant ...$variants): void
    {
        $bundle = ProductBundle::factory()->create();
        foreach ($variants as $variant) {
            ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
        }
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1])->assertOk();
    }

    private function createOrder(string $key = 'origin-checkout', array $extra = [])
    {
        return $this->postJson('/api/v1/orders', $this->checkoutPayload($this->payload($extra)), ['Idempotency-Key' => $key]);
    }

    private function twoWarehouses(): void
    {
        $directory = Mockery::mock(WarehouseDirectoryProvider::class);
        $directory->shouldReceive('list')->andReturn([
            ['code' => 'WH-TASIK', 'realname' => 'Tasikmalaya'],
            ['code' => 'WH-BDG', 'realname' => 'Bandung'],
        ]);
        $this->app->instance(WarehouseDirectoryProvider::class, $directory);
        $client = Mockery::mock(ExternalWarehouseDirectoryClient::class);
        $client->shouldReceive('detail')->andReturnUsing(fn (string $code): array => [
            'fulladdr' => 'Alamat '.$code, 'postal_code' => '', 'coordinates' => '',
        ]);
        $this->app->instance(ExternalWarehouseDirectoryClient::class, $client);
    }

    public function test_mixed_checkout_selects_common_tasikmalaya_without_stock_calls_and_snapshots_origin(): void
    {
        $this->addProduct();
        $this->addBundle($this->variant());
        $preview = $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk()
            ->assertJsonPath('data.stock_mode', 'unlimited')->assertJsonPath('data.stock_verification', 'pending_manual')
            ->assertJsonPath('data.shipping_origin.warehouse_code', 'WH-TASIK')->assertJsonPath('data.shipping_origin.warehouse_name', 'Tasikmalaya')
            ->assertJsonPath('data.shipping_origin.coordinates', null)->assertJsonPath('data.shipping.fee', '0.00');
        $response = $this->createOrder(extra: ['checkout_token' => $preview->json('data.checkout_token')])->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.payment_provider', 'manual')
            ->assertJsonPath('data.stock_mode', 'unlimited')->assertJsonPath('data.shipping_fee', '0.00');
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame($preview->json('data.shipping_origin'), $order->shipping_origin);
        $this->assertNull($order->stock_reservation_id);
        config(['checkout.stock_mode' => 'external']);
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.stock_mode', 'unlimited');
    }

    public function test_unassigned_variant_blocks_preview_and_order_with_actionable_error(): void
    {
        [$variant] = $this->addProduct([]);
        $this->postJson('/api/v1/checkout/preview', $this->payload())->assertStatus(409)
            ->assertJsonPath('code', 'WAREHOUSE_NOT_ASSIGNED')->assertJsonPath('data.variants.0.variant_id', $variant->id);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_NOT_ASSIGNED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_unassigned_bundle_component_blocks_checkout(): void
    {
        $missing = $this->variant([]);
        $this->addBundle($this->variant(), $missing);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_NOT_ASSIGNED')
            ->assertJsonPath('data.variants.0.variant_id', $missing->id);
    }

    public function test_common_warehouse_is_intersection_of_all_bundle_components(): void
    {
        $first = $this->variant(['WH-TASIK', 'WH-BDG']);
        $this->addBundle($first, $this->variant());
        $this->createOrder()->assertCreated()->assertJsonPath('data.shipping_origin.warehouse_code', 'WH-TASIK');
        $this->assertCount(2, $first->warehouseAssignments()->get());
    }

    public function test_items_without_common_warehouse_are_rejected_without_splitting_order(): void
    {
        $this->addProduct();
        $this->addProduct(['WH-BDG']);
        $this->postJson('/api/v1/checkout/preview', $this->payload())->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_MISMATCH');
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_MISMATCH');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_multiple_common_warehouses_require_preference_instead_of_claiming_nearest(): void
    {
        $this->addProduct(['WH-TASIK', 'WH-BDG']);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_SELECTION_REQUIRED');
        config(['checkout.preferred_warehouse_code' => 'WH-TASIK']);
        $this->createOrder()->assertCreated()->assertJsonPath('data.shipping_origin.warehouse_code', 'WH-TASIK');
    }

    public function test_preferred_warehouse_still_must_be_assigned_to_every_variant(): void
    {
        config(['checkout.preferred_warehouse_code' => 'WH-TASIK']);
        $this->addProduct(['WH-BDG']);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_NOT_AVAILABLE');
    }

    public function test_request_cannot_override_origin_stock_mode_or_shipping_fee(): void
    {
        $this->addProduct();
        $this->createOrder(extra: ['shipping_origin' => ['warehouse_code' => 'WH-BDG'], 'warehouse_code' => 'WH-BDG',
            'stock_mode' => 'external', 'shipping_fee' => 99999])->assertCreated()
            ->assertJsonPath('data.shipping_origin.warehouse_code', 'WH-TASIK')->assertJsonPath('data.stock_mode', 'unlimited')
            ->assertJsonPath('data.shipping_fee', '0.00');
    }

    public function test_only_selected_items_need_warehouse_assignments(): void
    {
        [, $selected] = $this->addProduct();
        [, $unselected] = $this->addProduct([]);
        $this->createOrder(extra: ['cart_item_ids' => [$selected->id]])->assertCreated()->assertJsonCount(1, 'data.items');
        $this->assertDatabaseHas('cart_items', ['id' => $unselected->id]);
    }

    public function test_old_order_origin_is_null(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id, 'order_number' => 'WAL-OLD-ORIGIN', 'idempotency_key' => 'old-origin',
            'status' => 'pending_payment', 'payment_provider' => 'manual', 'subtotal' => 15000, 'total' => 15000,
            'shipping_address' => $this->payload()['shipping_address'],
        ]);
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.shipping_origin', null)
            ->assertJsonPath('data.stock_mode', null);
    }

    public function test_origin_snapshot_and_idempotent_retry_survive_assignment_and_directory_changes(): void
    {
        [$variant] = $this->addProduct();
        $created = $this->createOrder()->assertCreated();
        $origin = $created->json('data.shipping_origin');
        $variant->warehouseAssignments()->delete();
        $directory = Mockery::mock(WarehouseDirectoryProvider::class);
        $directory->shouldNotReceive('list');
        $this->app->instance(WarehouseDirectoryProvider::class, $directory);
        $this->createOrder()->assertOk()->assertJsonPath('data.shipping_origin', $origin);
        $this->getJson('/api/v1/orders/'.$created->json('data.id'))->assertOk()->assertJsonPath('data.shipping_origin', $origin);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_changed_origin_after_preview_requires_new_preview(): void
    {
        [$variant] = $this->addProduct();
        $this->twoWarehouses();
        $token = $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk()->json('data.checkout_token');
        $variant->warehouseAssignments()->update(['warehouse_code' => 'WH-BDG']);
        $this->createOrder(extra: ['checkout_token' => $token])->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_CHANGED');
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_unavailable_warehouse_directory_or_detail_does_not_create_order(): void
    {
        $this->addProduct();
        $client = Mockery::mock(ExternalWarehouseDirectoryClient::class);
        $client->shouldReceive('detail')->andThrow(new WarehouseDirectoryUnavailableException('Detail tidak tersedia'));
        $this->app->instance(ExternalWarehouseDirectoryClient::class, $client);
        $this->createOrder()->assertStatus(503);
        $directory = Mockery::mock(WarehouseDirectoryProvider::class);
        $directory->shouldReceive('list')->andThrow(new WarehouseDirectoryUnavailableException('Direktori tidak tersedia'));
        $this->app->instance(WarehouseDirectoryProvider::class, $directory);
        $this->createOrder()->assertStatus(503);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_deleted_external_warehouse_is_not_silently_replaced(): void
    {
        $this->addProduct(['WH-DELETED']);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'WAREHOUSE_NOT_AVAILABLE');
    }

    public function test_manual_payment_can_use_external_stock_and_admin_confirms_or_releases_reservation(): void
    {
        config(['checkout.stock_mode' => 'external']);
        $warehouse = Mockery::mock(WarehouseStockProvider::class);
        $warehouse->shouldReceive('checkAvailability')->twice()->andReturn(['available' => true, 'items' => []]);
        $warehouse->shouldReceive('reserve')->twice()->andReturn('stock-paid', 'stock-cancelled');
        $warehouse->shouldReceive('confirm')->once()->with('stock-paid');
        $warehouse->shouldReceive('release')->once()->with('stock-cancelled');
        $this->app->instance(WarehouseStockProvider::class, $warehouse);
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        foreach (['paid', 'cancelled'] as $status) {
            $this->addProduct();
            $created = $this->createOrder('origin-'.$status)->assertCreated()->assertJsonPath('data.stock_mode', 'external')
                ->assertJsonPath('data.payment_provider', 'manual');
            $order = Order::findOrFail($created->json('data.id'));
            $service = app(ManualOrderService::class);
            if ($status === 'paid') {
                $service->confirmPaid($order, $admin, 'Pembayaran telah diterima.');
            } else {
                $service->cancel($order, $admin, 'Order dibatalkan admin.');
                $this->assertNull($order->fresh()->stock_reservation_id);
            }
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_unlimited_stock_is_independent_of_payment_mode(): void
    {
        config(['checkout.mode' => 'integrated']);
        $this->addProduct();
        $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk()
            ->assertJsonPath('data.stock_mode', 'unlimited')->assertJsonPath('data.payment_provider', 'midtrans')
            ->assertJsonPath('data.stock_verification', 'pending_manual');
        $this->createOrder()->assertCreated()->assertJsonPath('data.stock_mode', 'unlimited')
            ->assertJsonPath('data.payment_provider', 'midtrans')->assertJsonPath('data.status', 'pending_payment');
        $this->assertNull(Order::query()->sole()->stock_reservation_id);
    }

    public function test_external_stock_unavailable_blocks_manual_payment_order(): void
    {
        config(['checkout.stock_mode' => 'external']);
        $warehouse = Mockery::mock(WarehouseStockProvider::class);
        $warehouse->shouldReceive('checkAvailability')->once()->andReturn(['available' => false, 'items' => []]);
        $warehouse->shouldNotReceive('reserve');
        $this->app->instance(WarehouseStockProvider::class, $warehouse);
        $this->addProduct();
        $this->createOrder()->assertStatus(503)->assertJsonPath('code', 'STOCK_UNAVAILABLE');
        $this->assertDatabaseCount('orders', 0);
    }
}
