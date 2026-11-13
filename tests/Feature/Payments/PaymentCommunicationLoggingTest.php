<?php

namespace Tests\Feature\Payments;

use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Order;
use App\Models\PaymentCommunicationLog;
use App\Models\User;
use App\Services\Payments\MidtransPaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentCommunicationLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.midtrans.server_key' => 'audit-server-secret', 'services.midtrans.client_key' => 'audit-client-secret', 'services.midtrans.is_production' => false]);
        Http::preventStrayRequests();
    }

    private function payload(string $id = 'AUDIT-DIRECT'): array
    {
        return ['payment_type' => 'bank_transfer', 'transaction_details' => ['order_id' => $id, 'gross_amount' => 93000], 'bank_transfer' => ['bank' => 'bca']];
    }

    private function body(string $status = 'pending'): array
    {
        return ['status_code' => '201', 'transaction_status' => $status, 'merchant_id' => 'MERCHANT-AUDIT', 'va_numbers' => [['bank' => 'bca', 'va_number' => '123456789']]];
    }

    public function test_each_charge_status_and_cancel_has_a_separate_record_even_without_a_laravel_order(): void
    {
        Http::fakeSequence()->push($this->body(), 201)->push($this->body('settlement'), 200)->push(['status_code' => '202', 'transaction_status' => 'cancel'], 200);
        $gateway = app(MidtransPaymentGateway::class);
        $gateway->charge($this->payload());
        $gateway->status('AUDIT-DIRECT');
        $gateway->cancel('AUDIT-DIRECT');
        $logs = PaymentCommunicationLog::orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame(['charge', 'status', 'cancel'], $logs->pluck('operation')->all());
        $this->assertSame([201, 200, 200], $logs->pluck('http_status')->all());
        $this->assertSame('pending', $logs[0]->response_payload['transaction_status']);
        $this->assertSame('MERCHANT-AUDIT', $logs[0]->response_payload['merchant_id']);
        $this->assertSame('settlement', $logs[1]->response_payload['transaction_status']);
        $this->assertSame($this->payload(), $logs[0]->request_payload);
        $this->assertNull($logs[0]->order_id);
        $this->assertNull($logs[1]->request_payload);
        $this->assertNull($logs[2]->request_payload);
        foreach ($logs as $log) {
            $this->assertSame('sandbox', $log->environment);
            $this->assertSame('succeeded', $log->outcome);
            $this->assertNotNull($log->requested_at);
            $this->assertNotNull($log->finished_at);
            $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        }
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/cancel') && $request->body() === '');
    }

    public function test_log_is_linked_to_the_existing_payment_and_order(): void
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'order_number' => 'AUDIT-ORDER', 'idempotency_key' => 'audit',
            'status' => 'pending_payment', 'payment_provider' => 'midtrans', 'currency' => 'IDR', 'subtotal' => 93000, 'shipping_fee' => 0, 'total' => 93000, 'shipping_address' => []]);
        $payment = $order->payments()->create(['attempt_number' => 1, 'provider_order_id' => 'AUDIT-LINK-P000001', 'method' => 'bca_va',
            'environment' => 'sandbox', 'idempotency_key' => 'audit-pay', 'state' => 'creating', 'amount' => 93000, 'request_payload' => []]);
        Http::fake(['*' => Http::response($this->body(), 201)]);
        app(MidtransPaymentGateway::class)->charge($this->payload($payment->provider_order_id));
        $log = PaymentCommunicationLog::sole();
        $this->assertSame($order->id, $log->order_id);
        $this->assertSame($payment->id, $log->order_payment_id);
        $this->assertSame($log->id, $payment->communicationLogs()->sole()->id);
    }

    public function test_request_and_response_credentials_are_redacted_but_the_transport_is_unchanged(): void
    {
        $payload = [...$this->payload(), 'metadata' => ['server_key' => 'audit-server-secret', 'Authorization' => 'Basic hidden-auth', 'nested' => ['api_token' => 'hidden-token']]];
        Http::fake(['*' => Http::response([...$this->body(), 'signature_key' => 'hidden-signature', 'echo' => 'audit-server-secret audit-client-secret'], 201)]);
        app(MidtransPaymentGateway::class)->charge($payload);
        $log = PaymentCommunicationLog::sole();
        $this->assertSame('[REDACTED]', $log->request_payload['metadata']['server_key']);
        $this->assertSame('[REDACTED]', $log->request_payload['metadata']['nested']['api_token']);
        $this->assertSame('[REDACTED]', $log->response_payload['signature_key']);
        foreach (['audit-server-secret', 'audit-client-secret', 'hidden-auth', 'hidden-token', 'hidden-signature'] as $secret) {
            $this->assertStringNotContainsString($secret, $log->toJson());
        }
        Http::assertSent(fn ($request): bool => $request->data() === $payload);
    }

    public function test_timeout_is_logged_without_exception_message_or_http_status(): void
    {
        Http::fake(fn () => throw new ConnectionException('Authorization Basic hidden; audit-server-secret'));
        try {
            app(MidtransPaymentGateway::class)->charge($this->payload());
            $this->fail('Gateway must report unavailable.');
        } catch (PaymentGatewayUnavailableException) {
            $log = PaymentCommunicationLog::sole();
            $this->assertSame('connection_error', $log->outcome);
            $this->assertNull($log->http_status);
            $this->assertNotNull($log->finished_at);
            $this->assertStringNotContainsString('hidden', $log->toJson());
            $this->assertStringNotContainsString('audit-server-secret', $log->toJson());
        }
    }

    public function test_not_found_and_rejected_or_non_json_responses_are_logged(): void
    {
        Http::fakeSequence()->push(['status_code' => '404'], 404)->push(['status_code' => '401', 'status_message' => 'Denied'], 401)
            ->push('<html>audit-server-secret <script>alert(1)</script></html>', 502);
        $gateway = app(MidtransPaymentGateway::class);
        $this->assertNull($gateway->status('NOT-FOUND'));
        foreach (range(1, 2) as $ignored) {
            try {
                $gateway->charge($this->payload());
                $this->fail('Rejected response must fail.');
            } catch (PaymentGatewayUnavailableException) {
            }
        }
        $logs = PaymentCommunicationLog::orderBy('id')->get();
        $this->assertSame(['not_found', 'failed', 'failed'], $logs->pluck('outcome')->all());
        $this->assertSame([404, 401, 502], $logs->pluck('http_status')->all());
        $this->assertNull($logs[2]->response_payload);
        $this->assertStringContainsString('<script>', $logs[2]->response_body);
        $this->assertStringNotContainsString('audit-server-secret', $logs[2]->response_body);
    }

    public function test_request_is_not_sent_if_audit_storage_is_unavailable(): void
    {
        Schema::drop('payment_communication_logs');
        Http::fake();
        try {
            app(MidtransPaymentGateway::class)->charge($this->payload());
            $this->fail('Missing audit storage must stop the request.');
        } catch (PaymentGatewayUnavailableException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_response_save_failure_does_not_report_payment_success_and_keeps_the_started_log(): void
    {
        Http::fake(['*' => Http::response($this->body(), 201)]);
        PaymentCommunicationLog::updating(function (): void {
            throw new \RuntimeException('Audit storage write failed');
        });
        try {
            app(MidtransPaymentGateway::class)->charge($this->payload());
            $this->fail('Unpersisted response must not be reported as confirmed.');
        } catch (PaymentGatewayUnavailableException) {
            Http::assertSentCount(1);
            $log = PaymentCommunicationLog::sole();
            $this->assertSame('started', $log->outcome);
            $this->assertNull($log->finished_at);
        } finally {
            PaymentCommunicationLog::flushEventListeners();
        }
    }
}
