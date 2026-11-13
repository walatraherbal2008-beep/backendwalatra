<?php

namespace Tests\Audit;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\WithCheckoutWarehouse;
use Tests\TestCase;

/** Memverifikasi perbaikan cart dan mempertahankan reproduksi temuan di luar cakupan. */
class FlowAuditTest extends TestCase
{
    use RefreshDatabase, WithCheckoutWarehouse;

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.mode' => 'integrated', 'checkout.stock_mode' => 'unlimited', 'checkout.free_shipping' => true,
            'services.midtrans.server_key' => 'audit-placeholder', 'services.midtrans.is_production' => false]);
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        Http::preventStrayRequests();
    }

    public function test_deleted_listing_remains_readable_in_cart_and_preview_rejects_it(): void
    {
        $variant = ProductVariant::factory()->withWarehouse()->create();
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        $this->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $listing->delete();

        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.is_available', false)
            ->assertJsonPath('data.items.0.availability_message', 'Tidak tersedia');
        $this->postJson('/api/v1/checkout/preview')->assertStatus(409);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cart_store_query_parameter_does_not_filter_items(): void
    {
        foreach (range(1, 2) as $ignored) {
            $variant = ProductVariant::factory()->withWarehouse()->create();
            $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
            $this->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        }

        $this->getJson('/api/v1/cart?store_id='.$listing->store_id)->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_refresh_can_charge_unknown_attempt_when_provider_status_is_missing(): void
    {
        $order = Order::create([
            'user_id' => auth()->id(), 'order_number' => 'WAL-AUDIT-FLOW', 'idempotency_key' => 'audit-order',
            'status' => 'pending_payment', 'payment_provider' => 'midtrans', 'payment_integration' => 'core_api',
            'payment_due_at' => now()->addDay(), 'currency' => 'IDR', 'subtotal' => 30000, 'shipping_fee' => 0,
            'total' => 30000, 'shipping_address' => ['name' => 'Pembeli Audit', 'phone' => '081234567890', 'address' => 'Jalan Tes'],
        ]);
        $order->items()->create(['item_type' => 'bundle', 'sku' => 'AUDIT', 'product_name' => 'Audit', 'variant_name' => 'Bundle',
            'store_name' => 'Audit', 'quantity' => 1, 'unit_price' => 30000, 'subtotal' => 30000]);
        Http::fake(fn () => throw new ConnectionException('audit timeout'));
        $this->postJson('/api/v1/orders/'.$order->id.'/payment', ['method' => 'bca_va'], ['Idempotency-Key' => 'audit-payment'])->assertStatus(503);
        $payment = $order->fresh()->activePayment;
        $this->assertSame('unknown', $payment->state);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/status' => Http::response(['status_code' => '404'], 404),
            '*/charge' => Http::response([
                'order_id' => $payment->provider_order_id, 'transaction_id' => 'audit-transaction', 'currency' => 'IDR',
                'gross_amount' => '30000.00', 'status_code' => '201', 'transaction_status' => 'pending',
                'payment_type' => 'bank_transfer', 'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']],
            ], 201),
        ]);
        $this->postJson('/api/v1/orders/'.$order->id.'/payment/refresh')->assertOk()->assertJsonPath('data.payment.state', 'pending');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/charge'));
        $this->assertDatabaseCount('order_payments', 1);
    }
}
