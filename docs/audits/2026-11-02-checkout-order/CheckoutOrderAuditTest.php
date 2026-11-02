<?php

namespace Midtrans {
    class MT_Tests
    {
        public static $stubHttp = true;

        public static $stubHttpResponse = '{"token":"audit-token","redirect_url":"https://example.test/pay"}';

        public static $lastHttpRequest;
    }
}

namespace Tests\Audit {
    use App\Contracts\PaymentGateway;
    use App\Contracts\ShippingRateProvider;
    use App\Contracts\WarehouseStockProvider;
    use App\Models\Order;
    use App\Models\Product;
    use App\Models\ProductVariant;
    use App\Models\StoreProductListing;
    use App\Models\User;
    use App\Services\Payments\MidtransPaymentGateway;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Http;
    use Laravel\Sanctum\Sanctum;
    use Midtrans\MT_Tests;
    use Tests\TestCase;

    class CheckoutOrderAuditTest extends TestCase
    {
        use RefreshDatabase;

        private object $warehouse;

        private object $gateway;

        protected function setUp(): void
        {
            parent::setUp();
            Http::preventStrayRequests();
            config(['services.maxchat.enabled' => false, 'services.otp.expose_code' => true,
                'services.midtrans.server_key' => 'audit-server-key', 'queue.default' => 'sync']);
            $this->warehouse = new class implements WarehouseStockProvider
            {
                public array $confirmed = [];

                public array $released = [];

                public array $reserved = [];

                public ?\Closure $onReserve = null;

                public ?\Closure $onRelease = null;

                public function checkAvailability(array $items): array
                {
                    return ['available' => true, 'items' => $items];
                }

                public function reserve(array $items, string $reference): string
                {
                    $this->reserved[] = $items;
                    $id = 'audit-reservation-'.count($this->reserved);
                    $hook = $this->onReserve;
                    $this->onReserve = null;
                    if ($hook) {
                        $hook();
                    }

                    return $id;
                }

                public function confirm(string $reservationId): void
                {
                    $this->confirmed[] = $reservationId;
                }

                public function release(string $reservationId): void
                {
                    $this->released[] = $reservationId;
                    $hook = $this->onRelease;
                    $this->onRelease = null;
                    if ($hook) {
                        $hook();
                    }
                }
            };
            $this->gateway = new class implements PaymentGateway
            {
                public int $calls = 0;

                public bool $fail = false;

                public function createPayment(Order $order): array
                {
                    $this->calls++;
                    if ($this->fail) {
                        throw new \Exception('Simulated Midtrans transport failure');
                    }

                    return ['payment_reference' => 'audit-payment-'.$this->calls,
                        'payment_token' => 'audit-payment-'.$this->calls, 'payment_url' => null];
                }
            };
            $this->app->instance(WarehouseStockProvider::class, $this->warehouse);
            $this->app->instance(PaymentGateway::class, $this->gateway);
            $this->app->instance(ShippingRateProvider::class, new class implements ShippingRateProvider
            {
                public function quote(array $address, array $items): array
                {
                    return ['fee' => 10000, 'service' => 'REG', 'eta_days' => 3];
                }
            });
        }

        private function address(): array
        {
            return ['name' => 'Audit Buyer', 'phone' => '6289990001102', 'address' => 'Alamat simulasi audit',
                'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40135'];
        }

        private function cart(bool $authenticate = true): array
        {
            if ($authenticate) {
                Sanctum::actingAs(User::factory()->create(), ['customer-api']);
            }
            $product = Product::factory()->create();
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 15000]);
            $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);
            $this->postJson('/api/v1/cart/items', ['listing_id' => $listing->id,
                'variant_id' => $variant->id, 'quantity' => 2])->assertOk();

            return [$variant, $listing];
        }

        private function placeOrder(): Order
        {
            $response = $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()],
                ['Idempotency-Key' => 'audit-order'])->assertCreated();

            return Order::findOrFail($response->json('data.id'));
        }

        private function webhook(Order $order, string $status, ?string $fraud = 'accept')
        {
            $amount = number_format((float) $order->total, 2, '.', '');

            return $this->postJson('/api/v1/payments/midtrans/webhook', [
                'order_id' => $order->order_number, 'status_code' => '200', 'gross_amount' => $amount,
                'signature_key' => hash('sha512', $order->order_number.'200'.$amount.'audit-server-key'),
                'transaction_status' => $status, 'fraud_status' => $fraud,
            ]);
        }

        public function test_new_customer_can_login_complete_profile_and_buy_with_simulated_integrations(): void
        {
            $otp = $this->postJson('/api/v1/auth/customer/request-otp', ['phone' => '089990001102'])
                ->assertOk()->json('data');
            $login = $this->postJson('/api/v1/auth/customer/verify-otp', [
                'phone' => '089990001102', 'challenge_id' => $otp['challenge_id'],
                'otp' => $otp['otp'], 'device_name' => 'audit',
            ])->assertOk()->assertJsonPath('data.is_new_user', true);
            $this->withToken($login->json('data.token'));
            $this->patchJson('/api/v1/auth/customer/profile', ['name' => 'Audit Buyer'])->assertOk();
            $this->cart(false);
            $address = $this->postJson('/api/v1/addresses', ['label' => 'Audit', ...$this->address()])->assertCreated();
            $this->postJson('/api/v1/checkout/preview', ['address_id' => $address->json('data.id')])
                ->assertOk()->assertJsonPath('data.total', '40000.00');
            $order = $this->placeOrder();
            $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertOk();
            $this->webhook($order, 'settlement')->assertOk();
            $this->webhook($order, 'settlement')->assertOk();
            $this->assertSame('paid', $order->fresh()->status);
            $this->assertCount(1, $this->warehouse->confirmed);
            $this->assertDatabaseCount('cart_items', 0);
        }

        public function test_midtrans_actual_sdk_payload_matches_order_total_including_shipping(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            (new MidtransPaymentGateway)->createPayment($order->load('items'));
            $payload = MT_Tests::$lastHttpRequest['data_hash'];
            $this->assertSame((int) $order->total, (int) $payload['transaction_details']['gross_amount'],
                'SDK sends a different amount from the saved order.');
        }

        public function test_fraud_challenge_capture_does_not_mark_order_paid(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->webhook($order, 'capture', 'challenge')->assertOk();
            $this->assertSame('pending_payment', $order->fresh()->status);
            $this->assertCount(0, $this->warehouse->confirmed);
        }

        public function test_document_sdk_payment_amount_is_rejected_by_webhook(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            (new MidtransPaymentGateway)->createPayment($order->load('items'));
            $payload = MT_Tests::$lastHttpRequest['data_hash'];
            $amount = number_format((float) $payload['transaction_details']['gross_amount'], 2, '.', '');
            $this->postJson('/api/v1/payments/midtrans/webhook', [
                'order_id' => $order->order_number, 'status_code' => '200', 'gross_amount' => $amount,
                'signature_key' => hash('sha512', $order->order_number.'200'.$amount.'audit-server-key'),
                'transaction_status' => 'settlement', 'fraud_status' => 'accept',
            ])->assertUnprocessable()->assertJsonValidationErrors('gross_amount');
            $this->assertSame('pending_payment', $order->fresh()->status);
        }

        public function test_capture_without_fraud_verdict_does_not_mark_order_paid(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->webhook($order, 'capture', null)->assertOk();
            $this->assertSame('pending_payment', $order->fresh()->status);
        }

        public function test_cancelled_order_is_not_automatically_paid_without_stock_reservation(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();
            $this->webhook($order, 'settlement')->assertOk();
            $this->assertNotSame('paid', $order->fresh()->status);
        }

        public function test_old_settlement_does_not_undo_refund(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->webhook($order, 'settlement')->assertOk();
            $this->webhook($order, 'refund')->assertOk();
            $this->webhook($order, 'settlement')->assertOk();
            $this->assertSame('refunded', $order->fresh()->status);
        }

        public function test_cart_remains_readable_after_listing_soft_delete(): void
        {
            [, $listing] = $this->cart();
            $listing->delete();
            $this->getJson('/api/v1/cart')->assertOk();
        }

        public function test_cart_remains_readable_after_variant_soft_delete(): void
        {
            [$variant] = $this->cart();
            $variant->delete();
            $this->getJson('/api/v1/cart')->assertOk();
        }

        public function test_checkout_preview_validates_incomplete_shipping_address(): void
        {
            $this->cart();
            $this->postJson('/api/v1/checkout/preview', ['shipping_address' => []])->assertUnprocessable();
        }

        public function test_payment_retry_reuses_previously_created_payment(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $first = $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertOk();
            $second = $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertOk();
            $this->assertSame($first->json('data.payment_token'), $second->json('data.payment_token'));
            $this->assertSame(1, $this->gateway->calls);
        }

        public function test_payment_provider_exception_returns_service_unavailable(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->gateway->fail = true;
            $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertStatus(503);
        }

        public function test_invalid_signature_cannot_change_order(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->postJson('/api/v1/payments/midtrans/webhook', [
                'order_id' => $order->order_number, 'status_code' => '200', 'gross_amount' => '40000.00',
                'signature_key' => str_repeat('0', 128), 'transaction_status' => 'settlement',
            ])->assertUnprocessable();
            $this->assertSame('pending_payment', $order->fresh()->status);
        }

        public function test_other_customer_cannot_access_or_pay_for_an_order(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            Sanctum::actingAs(User::factory()->create(), ['customer-api']);
            $this->getJson('/api/v1/orders/'.$order->id)->assertNotFound();
            $this->postJson('/api/v1/orders/'.$order->id.'/payment')->assertNotFound();
            $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertNotFound();
        }

        public function test_order_is_retrieved_on_retry_with_same_idempotency_key(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()],
                ['Idempotency-Key' => 'audit-order'])->assertOk()->assertJsonPath('data.id', $order->id);
            $this->assertDatabaseCount('orders', 1);
            $this->assertCount(1, $this->warehouse->reserved);
        }

        public function test_document_current_price_snapshot_behavior(): void
        {
            [$variant] = $this->cart();
            $variant->update(['price' => 25000]);
            $order = $this->placeOrder();
            $this->assertSame('30000.00', $order->subtotal, 'Current behavior uses the earlier cart price.');
        }

        public function test_interleaved_orders_do_not_purchase_the_same_cart_twice(): void
        {
            $this->cart();
            $this->warehouse->onReserve = function (): void {
                $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()],
                    ['Idempotency-Key' => 'audit-second-request'])->assertCreated();
            };
            $this->placeOrder();
            $this->assertDatabaseCount('orders', 1);
        }

        public function test_interleaved_same_idempotency_key_returns_existing_order(): void
        {
            $this->cart();
            $this->warehouse->onReserve = function (): void {
                $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()],
                    ['Idempotency-Key' => 'audit-order'])->assertCreated();
            };
            $this->postJson('/api/v1/orders', ['shipping_address' => $this->address()],
                ['Idempotency-Key' => 'audit-order'])->assertSuccessful();
            $this->assertDatabaseCount('orders', 1);
        }

        public function test_payment_during_cancellation_is_not_overwritten_as_cancelled(): void
        {
            $this->cart();
            $order = $this->placeOrder();
            $this->warehouse->onRelease = function () use ($order): void {
                $this->webhook($order, 'settlement')->assertOk();
            };
            $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();
            $this->assertSame('paid', $order->fresh()->status);
        }
    }
}
