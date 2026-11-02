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
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Services\Shipping\UnavailableShippingRateProvider;
use App\Services\Warehouse\UnavailableWarehouseStockProvider;
use App\Support\CheckoutSimulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'checkout.simulation.enabled' => true,
            'checkout.simulation.shipping_fee' => 0,
            'services.maxchat.enabled' => false,
            'services.otp.expose_code' => true,
            'queue.default' => 'sync',
        ]);
        Http::preventStrayRequests();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('createPayment');
        $this->app->instance(PaymentGateway::class, $gateway);
    }

    private function address(): array
    {
        return [
            'name' => 'Pembeli Simulasi', 'phone' => '6289990001102',
            'province' => 'Jawa Barat', 'city' => 'Bandung',
            'address' => 'Alamat testing order', 'postal_code' => '40135',
        ];
    }

    private function addProduct(bool $authenticate = true): StoreProductListing
    {
        if ($authenticate) {
            Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        }

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 15000]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);
        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 2,
        ])->assertOk();

        return $listing;
    }

    private function createOrder(string $key = 'simulation-order')
    {
        return $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()], [
            'Idempotency-Key' => $key,
        ]);
    }

    public function test_customer_can_login_and_checkout_without_midtrans_or_external_fulfillment(): void
    {
        $otp = $this->postJson('/api/v1/auth/customer/request-otp', ['phone' => '089990001102'])
            ->assertOk()->json('data');
        $login = $this->postJson('/api/v1/auth/customer/verify-otp', [
            'phone' => '089990001102', 'challenge_id' => $otp['challenge_id'],
            'otp' => $otp['otp'], 'device_name' => 'order-simulation-test',
        ])->assertOk();
        $this->withToken($login->json('data.token'));
        $this->patchJson('/api/v1/auth/customer/profile', ['name' => 'Pembeli Simulasi'])->assertOk();
        $this->addProduct(false);

        $address = $this->postJson('/api/v1/addresses', ['label' => 'Rumah', ...$this->address()])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/checkout/preview', ['address_id' => $address])
            ->assertOk()
            ->assertJsonPath('data.simulation', true)
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.checkout_available', true)
            ->assertJsonPath('data.shipping.service', 'SIMULATION')
            ->assertJsonPath('data.shipping.fee', '0.00')
            ->assertJsonPath('data.total', '30000.00');

        $order = $this->postJson('/api/v1/orders', ['address_id' => $address], [
            'Idempotency-Key' => 'simulation-full-flow',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_provider', 'simulation')
            ->assertJsonPath('data.simulation', true)
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.total', '30000.00')
            ->assertJsonPath('data.items.0.quantity', 2);

        $id = $order->json('data.id');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $id, 'status' => 'paid']);
        $record = Order::findOrFail($id);
        $this->assertNull($record->stock_reservation_id);
        $this->assertStringStartsWith('SIM-PAY-', $record->payment_reference);
        $this->getJson('/api/v1/orders/'.$id)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/v1/orders/'.$id.'/payment')
            ->assertOk()->assertJsonPath('code', 'PAYMENT_SIMULATED')
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.payment_token', null);
        Http::assertNothingSent();
    }

    public function test_simulation_order_retry_returns_same_order_and_history(): void
    {
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        $this->createOrder()->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public function test_bundle_order_keeps_component_snapshot_in_simulation(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $variant = ProductVariant::factory()->create();
        $bundle = ProductBundle::factory()->create(['price' => 40000, 'status' => 'active']);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 2,
        ]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', [
            'type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 3,
        ])->assertOk();
        $this->createOrder()->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total', '120000.00')
            ->assertJsonPath('data.items.0.bundle_components.0.sku', $variant->sku)
            ->assertJsonPath('data.items.0.bundle_components.0.quantity', 2);
    }

    public function test_simulation_shipping_fee_can_be_configured(): void
    {
        config(['checkout.simulation.shipping_fee' => 10000]);
        $this->addProduct();
        $this->createOrder()->assertCreated()
            ->assertJsonPath('data.shipping_fee', '10000.00')
            ->assertJsonPath('data.total', '40000.00');
    }

    public function test_simulation_still_rejects_unavailable_cart_and_invalid_address(): void
    {
        $listing = $this->addProduct();
        $this->postJson('/api/v1/orders', ['shipping_address' => []], [
            'Idempotency-Key' => 'invalid-address',
        ])->assertUnprocessable();
        $listing->update(['status' => 'inactive']);
        $this->createOrder()->assertStatus(409)->assertJsonPath('code', 'STALE_CART');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_other_customer_cannot_access_simulated_payment(): void
    {
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
        $this->postJson('/api/v1/orders/'.$id.'/payment')->assertNotFound();
    }

    public function test_midtrans_webhook_cannot_change_a_simulated_order(): void
    {
        $this->addProduct();
        $id = $this->createOrder()->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        config(['services.midtrans.server_key' => 'test-server-key']);
        $amount = '30000.00';
        $this->postJson('/api/v1/payments/midtrans/webhook', [
            'order_id' => $order->order_number, 'status_code' => '200', 'gross_amount' => $amount,
            'signature_key' => hash('sha512', $order->order_number.'200'.$amount.'test-server-key'),
            'transaction_status' => 'refund',
        ])->assertNotFound();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_production_cannot_enable_simulation_even_when_flag_is_true(): void
    {
        $this->app->instance('env', 'production');
        config(['services.warehouse.enabled' => false, 'services.shipping.enabled' => false]);
        $this->assertFalse(CheckoutSimulation::enabled());
        $this->assertInstanceOf(UnavailableWarehouseStockProvider::class, app(WarehouseStockProvider::class));
        $this->assertInstanceOf(UnavailableShippingRateProvider::class, app(ShippingRateProvider::class));
        $this->artisan('orders:prepare-simulation')->assertFailed();
    }

    public function test_disabling_simulation_restores_external_checkout_requirements(): void
    {
        config(['checkout.simulation.enabled' => false, 'services.warehouse.enabled' => false]);
        $this->addProduct();
        $this->postJson('/api/v1/checkout/preview')->assertStatus(503)
            ->assertJsonPath('data.checkout_available', false)
            ->assertJsonPath('data.simulation', false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_prepare_command_can_run_twice_without_resetting_catalog(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->artisan('orders:prepare-simulation')->assertSuccessful();
        $this->artisan('orders:prepare-simulation')->assertSuccessful();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('stores', 1);
        $this->assertDatabaseCount('store_product_listings', 1);
        $this->getJson('/api/v1/catalog/products')->assertOk()
            ->assertJsonPath('data.0.product.id', $product->id);
    }
}
