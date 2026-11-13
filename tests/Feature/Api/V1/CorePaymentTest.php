<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessMidtransNotification;
use App\Jobs\ReconcileOrderPayment;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentCommunicationLog;
use App\Models\PaymentNotification;
use App\Models\User;
use App\Services\Payments\OrderPayments;
use App\Support\PaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CorePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private array $remote = [];

    private bool $cancelSettles = false;

    private bool $delayedCancel = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.midtrans.server_key' => 'sandbox-test-key', 'services.midtrans.is_production' => false,
            'services.midtrans.methods' => array_keys(PaymentMethods::LABELS), 'services.midtrans.va_expiry_minutes' => 1440,
            'services.midtrans.qris_expiry_minutes' => 15]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer, ['customer-api']);
        $this->fakeMidtrans();
    }

    private function fakeMidtrans(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/qr-code')) {
                return Http::response("\x89PNG\r\n\x1a\nFAKE", 200, ['Content-Type' => 'image/png']);
            }
            if ($path === '/v2/charge') {
                $data = $request->data();
                $id = $data['transaction_details']['order_id'];
                if (isset($this->remote[$id])) {
                    return Http::response(['status_code' => '406'], 406);
                }
                $body = ['order_id' => $id, 'transaction_id' => 'trx-'.md5($id), 'currency' => 'IDR',
                    'gross_amount' => $data['transaction_details']['gross_amount'].'.00', 'status_code' => '201',
                    'transaction_status' => 'pending', 'fraud_status' => 'accept', 'payment_type' => $data['payment_type']];
                if ($body['payment_type'] === 'qris') {
                    $body['actions'] = [['name' => 'generate-qr-code-v2', 'method' => 'GET', 'url' => 'https://merchants-app.sbx.midtrans.com/v4/qris/gopay/'.md5($id).'/qr-code']];
                } elseif ($body['payment_type'] === 'echannel') {
                    $body['bill_key'] = '123456';
                    $body['biller_code'] = '70012';
                } elseif ($data['bank_transfer']['bank'] === 'permata') {
                    $body['permata_va_number'] = '85001234567';
                } else {
                    $body['va_numbers'] = [['bank' => $data['bank_transfer']['bank'], 'va_number' => '123456789']];
                }
                $this->remote[$id] = $body;

                return Http::response($body, 201);
            }
            preg_match('~^/v2/([^/]+)/(status|cancel)$~', $path, $matches);
            $id = rawurldecode($matches[1] ?? '');
            if (! isset($this->remote[$id])) {
                return Http::response(['status_code' => '404'], 404);
            }
            if (($matches[2] ?? '') === 'cancel') {
                if (! $this->delayedCancel) {
                    $this->remote[$id]['transaction_status'] = $this->cancelSettles ? 'settlement' : 'cancel';
                    $this->remote[$id]['status_code'] = $this->cancelSettles ? '200' : '202';
                }
            }

            return Http::response($this->remote[$id]);
        });
    }

    private function order(array $attributes = []): Order
    {
        $order = Order::create([
            'user_id' => $this->customer->id, 'order_number' => 'WAL-'.bin2hex(random_bytes(5)), 'idempotency_key' => bin2hex(random_bytes(8)),
            'status' => 'pending_payment', 'payment_provider' => 'midtrans', 'payment_integration' => 'core_api',
            'payment_due_at' => now()->addDay(), 'currency' => 'IDR', 'subtotal' => 30000, 'shipping_fee' => 0, 'total' => 30000,
            'shipping_address' => ['name' => 'Pembeli Core', 'phone' => '628123456789', 'address' => 'Jalan Tes'], ...$attributes,
        ]);
        // Bundle-style snapshot needs no live listing/variant to issue payment.
        $order->items()->create(['item_type' => 'bundle', 'sku' => 'BUNDLE-CORE', 'product_name' => 'Produk Core', 'variant_name' => 'Bundle',
            'store_name' => 'Toko', 'quantity' => 2, 'unit_price' => 15000, 'subtotal' => 30000]);

        return $order;
    }

    private function pay(Order $order, string $method = 'bca_va', string $key = 'payment-1')
    {
        return $this->postJson('/api/v1/orders/'.$order->id.'/payment', ['method' => $method], ['Idempotency-Key' => $key]);
    }

    private function refresh(Order $order)
    {
        return $this->postJson('/api/v1/orders/'.$order->id.'/payment/refresh');
    }

    private function setStatus(OrderPayment $payment, string $status): void
    {
        $this->remote[$payment->provider_order_id]['transaction_status'] = $status;
        $this->remote[$payment->provider_order_id]['status_code'] = in_array($status, ['settlement', 'capture', 'refund', 'partial_refund'], true) ? '200' : '202';
    }

    private function notification(OrderPayment $payment, string $status = 'settlement'): array
    {
        $data = ['order_id' => $payment->provider_order_id, 'transaction_id' => $payment->transaction_id,
            'status_code' => '200', 'gross_amount' => $payment->amount, 'transaction_status' => $status, 'fraud_status' => 'accept'];
        $data['signature_key'] = hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].'sandbox-test-key');

        return $data;
    }

    public function test_named_webhook_route_accepts_public_post_without_customer_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $path = route('payments.midtrans.webhook', absolute: false);
        $this->assertSame('/api/v1/payments/midtrans/webhook', $path);
        $this->postJson($path, [])->assertUnprocessable()->assertJsonValidationErrors(['signature_key']);
        $this->postJson($path, [
            'order_id' => 'public-webhook-test', 'status_code' => '200', 'gross_amount' => '10000.00',
            'transaction_status' => 'settlement', 'signature_key' => str_repeat('0', 128),
        ])->assertForbidden()->assertJsonPath('code', 'INVALID_SIGNATURE');
        $this->getJson($path)->assertStatus(405);
        $this->assertDatabaseCount('payment_notifications', 0);
    }

    public function test_all_methods_create_correct_core_payload_and_normalized_instructions(): void
    {
        $this->getJson('/api/v1/payment-methods')->assertOk()->assertJsonCount(6, 'data');
        foreach (array_keys(PaymentMethods::LABELS) as $method) {
            $order = $this->order();
            $response = $this->pay($order, $method)->assertCreated()->assertJsonPath('data.payment.method', $method)
                ->assertJsonPath('data.payment.state', 'pending')->assertJsonPath('data.payment.amount', '30000.00')
                ->assertJsonPath('data.payment_token', null);
            $payment = $order->fresh()->activePayment;
            $this->assertSame(PaymentMethods::type($method), $payment->request_payload['payment_type']);
            $this->assertLessThanOrEqual(PaymentMethods::ttl($method) * 60, $payment->expires_at->timestamp - now()->timestamp);
            $this->assertSame(30000, $payment->request_payload['transaction_details']['gross_amount']);
            if ($method === 'qris') {
                $this->get($response->json('data.payment.qr_code_url'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
            } elseif ($method === 'mandiri_bill') {
                $response->assertJsonPath('data.payment.bill_key', '123456')->assertJsonPath('data.payment.biller_code', '70012');
            } else {
                $this->assertNotEmpty($response->json('data.payment.va_number'));
            }
        }
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/charge')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sandbox-test-key:')));
    }

    public function test_get_status_preserves_qr_instructions_when_provider_omits_actions(): void
    {
        $order = $this->order();
        $url = $this->pay($order, 'qris')->assertCreated()->json('data.payment.qr_code_url');
        $payment = $order->fresh()->activePayment;
        unset($this->remote[$payment->provider_order_id]['actions']);
        $this->refresh($order)->assertOk()->assertJsonPath('data.payment.qr_code_url', $url);
        $this->get($url)->assertOk();
    }

    public function test_cancel_request_uses_empty_body_instead_of_json_array(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cancel') && $request->body() === '');
    }

    public function test_retries_reuse_same_payment_and_reject_key_reuse_for_different_method(): void
    {
        $order = $this->order();
        $id = $this->pay($order)->assertCreated()->json('data.payment.id');
        $this->pay($order)->assertOk()->assertJsonPath('data.payment.id', $id);
        $this->pay($order, 'bca_va', 'alias-key')->assertOk()->assertJsonPath('data.payment.id', $id);
        $this->pay($order, 'qris', 'alias-key')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/v2/charge')));
    }

    public function test_switch_cancels_old_transaction_then_creates_unique_new_attempt(): void
    {
        $order = $this->order();
        $first = $this->pay($order)->assertCreated()->json('data.payment.id');
        $this->pay($order, 'qris', 'payment-2')->assertCreated()->assertJsonPath('data.payment.method', 'qris');
        $this->assertSame('cancelled', OrderPayment::findOrFail($first)->state);
        $this->assertDatabaseCount('order_payments', 2);
        $this->assertSame(2, $order->fresh()->activePayment->attempt_number);
        $this->assertCount(2, array_unique(OrderPayment::pluck('provider_order_id')->all()));
    }

    public function test_settled_payment_cannot_be_switched_or_order_cancelled(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $this->setStatus($order->fresh()->activePayment, 'settlement');
        $this->pay($order, 'qris', 'payment-2')->assertStatus(409);
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertStatus(409);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_expired_qr_can_be_reissued_until_merchant_deadline(): void
    {
        $order = $this->order();
        $this->pay($order, 'qris')->assertCreated();
        $this->setStatus($order->fresh()->activePayment, 'expire');
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'pending_payment')->assertJsonPath('data.payment.state', 'expired');
        $this->pay($order, 'qris', 'qr-new')->assertCreated();
        $order->update(['payment_due_at' => now()->subSecond()]);
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'expired');
        $this->pay($order, 'qris', 'qr-after-deadline')->assertStatus(409);
    }

    public function test_status_refuses_wrong_amount_currency_id_and_fraud_challenge(): void
    {
        foreach (['gross_amount' => '1.00', 'currency' => 'USD', 'order_id' => 'unrelated', 'transaction_id' => 'wrong-id'] as $field => $value) {
            $order = $this->order();
            $this->pay($order)->assertCreated();
            $payment = $order->fresh()->activePayment;
            $this->setStatus($payment, 'settlement');
            $this->remote[$payment->provider_order_id][$field] = $value;
            $this->refresh($order)->assertStatus(503);
            $this->assertSame('pending_payment', $order->fresh()->status);
        }
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $payment = $order->fresh()->activePayment;
        $this->setStatus($payment, 'settlement');
        $this->remote[$payment->provider_order_id]['fraud_status'] = 'challenge';
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'payment_review');
    }

    public function test_webhook_is_durable_idempotent_and_uses_status_api_instead_of_untrusted_status(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $payment = $order->fresh()->activePayment;
        $data = $this->notification($payment);
        $this->postJson('/api/v1/payments/midtrans/webhook', [...$data, 'signature_key' => str_repeat('0', 128)])->assertForbidden();
        $this->assertDatabaseCount('payment_notifications', 0);
        $this->postJson('/api/v1/payments/midtrans/webhook', $data)->assertOk();
        $this->postJson('/api/v1/payments/midtrans/webhook', $data)->assertOk();
        $this->assertDatabaseCount('payment_notifications', 1);
        Queue::assertPushed(ProcessMidtransNotification::class);
        $notification = PaymentNotification::query()->sole();
        (new ProcessMidtransNotification($notification->id))->handle(app(OrderPayments::class));
        $this->assertSame('pending_payment', $order->fresh()->status);
        $notification->refresh()->update(['processed_at' => null]);
        $this->setStatus($payment, 'settlement');
        (new ProcessMidtransNotification($notification->id))->handle(app(OrderPayments::class));
        (new ProcessMidtransNotification($notification->id))->handle(app(OrderPayments::class));
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(1, $order->statusHistories()->where('status', 'paid')->count());
    }

    public function test_out_of_order_pending_does_not_downgrade_paid_and_reversal_requires_review(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $payment = $order->fresh()->activePayment;
        $this->setStatus($payment, 'settlement');
        $this->refresh($order)->assertOk();
        $this->setStatus($payment, 'pending');
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'paid');
        $this->setStatus($payment, 'deny');
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'payment_review');
    }

    public function test_unknown_charge_recovers_same_provider_id_after_timeout(): void
    {
        $order = $this->order();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->pay($order)->assertStatus(503);
        $payment = $order->fresh()->activePayment;
        $this->assertSame('unknown', $payment->state);
        $this->fakeMidtrans();
        $this->pay($order)->assertOk()->assertJsonPath('data.payment.provider_order_id', $payment->provider_order_id);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_busy_order_returns_processing_and_stale_operation_can_recover(): void
    {
        $order = $this->order(['payment_operation_token' => '00000000-0000-4000-8000-000000000001', 'payment_operation_started_at' => now()]);
        $this->pay($order)->assertStatus(202)->assertJsonPath('code', 'PAYMENT_PROCESSING');
        $order->update(['payment_operation_started_at' => now()->subMinutes(3)]);
        $this->pay($order)->assertCreated();
    }

    public function test_payment_winning_cancel_race_is_paid_and_not_reported_as_cancelled(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $this->cancelSettles = true;
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertStatus(409);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_switch_waits_for_confirmed_cancel_before_creating_another_charge(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $old = $order->fresh()->activePayment;
        $this->delayedCancel = true;
        $this->pay($order, 'qris', 'switch-key')->assertStatus(202);
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertSame('cancelling', $old->fresh()->state);
        $this->setStatus($old, 'cancel');
        $this->pay($order, 'qris', 'switch-key')->assertCreated();
        $this->assertDatabaseCount('order_payments', 2);
    }

    public function test_switch_does_not_recharge_an_unknown_attempt_when_midtrans_status_is_not_found(): void
    {
        $order = $this->order();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->pay($order)->assertStatus(503);
        $old = $order->fresh()->activePayment;
        $this->assertSame('unknown', $old->state);
        $this->fakeMidtrans();
        unset($this->remote[$old->provider_order_id]);

        $this->pay($order, 'qris', 'switch-unknown')->assertStatus(202)
            ->assertJsonPath('code', 'PAYMENT_PROCESSING');
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertSame('unknown', $old->fresh()->state);
        $this->assertSame(1, PaymentCommunicationLog::where('order_payment_id', $old->id)->where('operation', 'charge')->count());

        // The timed-out charge may have reached Midtrans despite its missing status response.
        $this->remote[$old->provider_order_id] = [
            'order_id' => $old->provider_order_id, 'transaction_id' => 'trx-'.md5($old->provider_order_id),
            'currency' => 'IDR', 'gross_amount' => '30000.00', 'status_code' => '201',
            'transaction_status' => 'pending', 'fraud_status' => 'accept', 'payment_type' => 'bank_transfer',
            'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']],
        ];

        $this->pay($order, 'qris', 'switch-unknown')->assertCreated()->assertJsonPath('data.payment.method', 'qris');
        $this->assertSame('cancelled', $old->fresh()->state);
        $this->assertDatabaseCount('order_payments', 2);
    }

    public function test_refresh_does_not_reissue_an_unknown_charge_when_status_is_not_found(): void
    {
        $order = $this->order();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->pay($order)->assertStatus(503);
        $payment = $order->fresh()->activePayment;
        $this->fakeMidtrans();
        unset($this->remote[$payment->provider_order_id]);

        $this->refresh($order)->assertOk()->assertJsonPath('data.payment.state', 'unknown');
        $this->assertSame(1, PaymentCommunicationLog::where('order_payment_id', $payment->id)->where('operation', 'charge')->count());
        $this->assertSame('unknown', $payment->fresh()->state);
    }

    public function test_paid_refund_is_synchronized_and_qr_fetch_does_not_send_server_key(): void
    {
        $order = $this->order();
        $url = $this->pay($order, 'qris')->assertCreated()->json('data.payment.qr_code_url');
        $this->get($url)->assertOk();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/qr-code') && ! $request->hasHeader('Authorization'));
        $payment = $order->fresh()->activePayment;
        $this->setStatus($payment, 'settlement');
        $this->refresh($order)->assertOk();
        $this->setStatus($payment, 'refund');
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'refunded');
    }

    public function test_inbox_survives_queue_failure_and_can_be_processed_later(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $payment = $order->fresh()->activePayment;
        $this->setStatus($payment, 'settlement');
        Bus::partialMock()->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue unavailable'));
        $this->postJson('/api/v1/payments/midtrans/webhook', $this->notification($payment))->assertOk();
        $notification = PaymentNotification::query()->sole();
        $this->assertNull($notification->processed_at);
        (new ProcessMidtransNotification($notification->id))->handle(app(OrderPayments::class));
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_fractional_amount_is_not_silently_rounded(): void
    {
        $order = $this->order(['total' => '30000.50']);
        $this->pay($order)->assertUnprocessable()->assertJsonValidationErrors('amount');
        Http::assertNothingSent();
    }

    public function test_payment_ownership_validation_and_qr_expiry_are_enforced(): void
    {
        $order = $this->order();
        $this->postJson('/api/v1/orders/'.$order->id.'/payment', [])->assertUnprocessable();
        $this->pay($order, 'invalid')->assertUnprocessable();
        $qr = $this->pay($order, 'qris')->assertCreated()->json('data.payment.qr_code_url');
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $this->getJson('/api/v1/orders/'.$order->id.'/payment')->assertNotFound();
        $this->getJson($qr)->assertNotFound();
        Sanctum::actingAs($this->customer, ['customer-api']);
        $order->fresh()->activePayment->update(['expires_at' => now()->subSecond()]);
        $this->getJson($qr)->assertStatus(410);
    }

    public function test_cancelled_order_closes_provider_and_late_payment_requires_review(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $payment = $order->fresh()->activePayment;
        $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancel', $this->remote[$payment->provider_order_id]['transaction_status']);
        $this->setStatus($payment, 'settlement');
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'payment_review');
    }

    public function test_old_attempt_payment_and_multiple_settlements_are_flagged(): void
    {
        $order = $this->order();
        $this->pay($order)->assertCreated();
        $old = $order->fresh()->activePayment;
        $this->pay($order, 'qris', 'new-key')->assertCreated();
        $this->setStatus($old, 'settlement');
        app(OrderPayments::class)->refresh($order, $old->id);
        $this->assertSame('payment_review', $order->fresh()->status);
        $this->setStatus($order->fresh()->activePayment, 'settlement');
        $this->refresh($order)->assertOk()->assertJsonPath('data.order_status', 'payment_review');
    }

    public function test_qr_proxy_rejects_foreign_hosts_and_reconciliation_dispatches_pending_records(): void
    {
        $order = $this->order();
        $qr = $this->pay($order, 'qris')->assertCreated()->json('data.payment.qr_code_url');
        $payment = $order->fresh()->activePayment;
        $body = $payment->response_payload;
        $body['actions'][0]['url'] = 'https://example.com/v4/qris/test/qr-code';
        $payment->update(['response_payload' => $body]);
        $this->getJson($qr)->assertStatus(503);
        $this->postJson('/api/v1/payments/midtrans/webhook', $this->notification($payment))->assertOk();
        $this->artisan('payments:reconcile')->assertSuccessful();
        Queue::assertPushed(ReconcileOrderPayment::class);
        Queue::assertPushed(ProcessMidtransNotification::class);
    }
}
