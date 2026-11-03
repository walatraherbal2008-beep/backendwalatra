<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\PaymentGateway;
use App\Contracts\ShippingRateProvider;
use App\Contracts\WarehouseStockProvider;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Services\Marketplace\ManualOrderService;
use App\Support\CheckoutSimulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'checkout.mode' => 'manual',
            'checkout.manual.shipping_fee' => 10000,
            'checkout.manual.payment_instructions' => 'Hubungi admin untuk rekening pembayaran.',
            'checkout.simulation.enabled' => true,
        ]);
        Http::preventStrayRequests();
        foreach ([PaymentGateway::class => ['createPayment'], ShippingRateProvider::class => ['quote'],
            WarehouseStockProvider::class => ['checkAvailability', 'reserve', 'confirm', 'release']] as $contract => $methods) {
            $mock = Mockery::mock($contract);
            foreach ($methods as $method) {
                $mock->shouldNotReceive($method);
            }
            $this->app->instance($contract, $mock);
        }
    }

    private function address(): array
    {
        return ['name' => 'Pembeli Manual', 'phone' => '6289990001102', 'address' => 'Alamat pesanan manual',
            'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135'];
    }

    private function addProduct(?string $storeCode = null): StoreProductListing
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 15000]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);
        if ($storeCode !== null) {
            $listing->store->forceFill(['code' => $storeCode])->save();
        }
        $this->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 2])->assertOk();

        return $listing;
    }

    private function createOrder(string $key = 'manual-order')
    {
        return $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()], ['Idempotency-Key' => $key]);
    }

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['user_type' => 'admin']);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_production_customer_orders_and_admin_confirms_payment_without_external_services(): void
    {
        $this->app->instance('env', 'production');
        $user = User::factory()->create();
        $this->withToken($user->createToken('manual-order-test', ['customer-api'])->plainTextToken);
        $this->addProduct();
        $addressId = $this->postJson('/api/v1/addresses', ['label' => 'Rumah', ...$this->address()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/checkout/preview', ['address_id' => $addressId])
            ->assertOk()->assertJsonPath('data.simulation', false)
            ->assertJsonPath('data.payment_provider', 'manual')
            ->assertJsonPath('data.stock_verification', 'pending_manual')
            ->assertJsonPath('data.payment_required', true)
            ->assertJsonPath('data.shipping.service', 'MANUAL')
            ->assertJsonPath('data.total', '40000.00');
        $response = $this->postJson('/api/v1/orders', ['address_id' => $addressId], ['Idempotency-Key' => 'manual-flow'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.simulation', false)->assertJsonPath('data.payment_provider', 'manual')
            ->assertJsonPath('data.payment_required', true)->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('data.total', '40000.00');
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertNull($order->stock_reservation_id);
        $this->assertNull($order->payment_reference);
        $this->assertDatabaseCount('cart_items', 0);
        $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertOk()
            ->assertJsonPath('code', 'MANUAL_PAYMENT_REQUIRED')
            ->assertJsonPath('data.instructions', 'Hubungi admin untuk rekening pembayaran.')
            ->assertJsonPath('data.payment_token', null);
        $admin = $this->admin();
        app(ManualOrderService::class)->confirmPaid($order, $admin, 'Stok tersedia dan dana sudah diterima.', 'TRANSFER-001');
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.payment_instructions', null);
        $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertOk()->assertJsonPath('code', 'MANUAL_PAYMENT_CONFIRMED');
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'status' => 'paid', 'created_by' => $admin->id]);
        $this->assertSame('TRANSFER-001', $order->fresh()->payment_reference);
        Http::assertNothingSent();
    }

    public function test_manual_checkout_retry_uses_existing_order_and_does_not_duplicate_history(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        $this->createOrder()->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public function test_production_customer_can_purchase_existing_product_regardless_of_simulation_store_code(): void
    {
        $this->app->instance('env', 'production');
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $listing = $this->addProduct(CheckoutSimulation::STORE_CODE);
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonPath('data.0.listing_id', $listing->id);
        $this->postJson('/api/v1/checkout/preview', ['shipping_address' => $this->address()])
            ->assertOk()->assertJsonPath('data.checkout_available', true);
        $id = $this->createOrder()->assertCreated()->assertJsonPath('data.payment_provider', 'manual')
            ->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.simulation', false)->json('data.id');
        $order = Order::findOrFail($id);
        app(ManualOrderService::class)->confirmPaid($order, $this->admin(), 'Dana diterima dan barang tersedia.');
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_existing_bundle_is_purchasable_regardless_of_simulation_store_code(): void
    {
        $this->app->instance('env', 'production');
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $store = Store::factory()->create();
        $store->forceFill(['code' => CheckoutSimulation::STORE_CODE])->save();
        $variant = ProductVariant::factory()->create();
        $bundle = ProductBundle::factory()->create(['price' => 40000]);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
        $listing = StoreBundleListing::factory()->create(['store_id' => $store->id, 'product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/checkout/preview', ['shipping_address' => $this->address()])->assertOk();
        $this->createOrder()->assertCreated()->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_provider', 'manual')->assertJsonPath('data.total', '50000.00')
            ->assertJsonPath('data.items.0.bundle_components.0.sku', $variant->sku);
    }

    public function test_bundle_components_and_manual_shipping_are_snapshotted(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $variant = ProductVariant::factory()->create();
        $bundle = ProductBundle::factory()->create(['price' => 40000]);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 3])->assertOk();
        $this->createOrder()->assertCreated()->assertJsonPath('data.total', '130000.00')
            ->assertJsonPath('data.items.0.bundle_components.0.sku', $variant->sku)
            ->assertJsonPath('data.items.0.bundle_components.0.quantity', 2);
    }

    public function test_customer_can_cancel_pending_manual_order_without_external_release(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/orders/'.$id.'/payment')->assertNotFound();
        $this->assertNull(Order::findOrFail($id)->paid_at);
    }

    public function test_customer_cannot_cancel_order_after_admin_confirmation(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->addProduct();
        $order = Order::findOrFail($this->createOrder()->assertCreated()->json('data.id'));
        app(ManualOrderService::class)->confirmPaid($order, $this->admin(), 'Dana sudah masuk, stok tersedia.');
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertUnprocessable();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_customer_cannot_access_or_pay_for_another_customers_order(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
        $this->postJson('/api/v1/orders/'.$id.'/payment')->assertNotFound();
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertNotFound();
    }

    public function test_invalid_or_unavailable_cart_does_not_create_order(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->createOrder()->assertUnprocessable()->assertJsonPath('code', 'CART_EMPTY');
        $listing = $this->addProduct();
        $this->postJson('/api/v1/checkout/preview', ['shipping_address' => []])->assertUnprocessable();
        $this->postJson('/api/v1/orders', ['shipping_address' => []], ['Idempotency-Key' => 'invalid'])->assertUnprocessable();
        $listing->update(['status' => 'inactive']);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'STALE_CART');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_cannot_set_paid_status_or_switch_payment_provider_from_request(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->addProduct();
        $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address(), 'status' => 'paid', 'payment_provider' => 'simulation',
            'simulation' => true, 'paid_at' => now()->toIso8601String(), 'shipping_fee' => 0,
        ], ['Idempotency-Key' => 'tampered'])->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')->assertJsonPath('data.payment_provider', 'manual')
            ->assertJsonPath('data.paid_at', null)->assertJsonPath('data.total', '40000.00');
    }

    public function test_midtrans_webhook_cannot_change_manual_order(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        config(['services.midtrans.server_key' => 'manual-test-key']);
        $this->postJson('/api/v1/payments/midtrans/webhook', [
            'order_id' => $order->order_number, 'status_code' => '200', 'gross_amount' => '40000.00',
            'signature_key' => hash('sha512', $order->order_number.'200'.'40000.00'.'manual-test-key'),
            'transaction_status' => 'settlement',
        ])->assertNotFound();
        $this->assertSame('pending_payment', $order->fresh()->status);
    }
}
