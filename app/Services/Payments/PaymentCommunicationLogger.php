<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentCommunicationLog;
use App\Support\PaymentMethods;
use App\Support\PaymentPayloadSanitizer;
use Throwable;

class PaymentCommunicationLogger
{
    public function start(string $method, string $endpoint, string $operation, ?string $providerId, array $payload): PaymentCommunicationLog
    {
        try {
            $environment = PaymentMethods::environment();
            $payment = $providerId === null ? null : OrderPayment::where('environment', $environment)->where('provider_order_id', $providerId)->first(['id', 'order_id']);
            $orderId = $payment?->order_id ?? ($providerId === null ? null : Order::where('payment_provider', 'midtrans')->where('order_number', $providerId)->value('id'));

            return PaymentCommunicationLog::create(PaymentPayloadSanitizer::sanitize([
                'order_id' => $orderId, 'order_payment_id' => $payment?->id,
                'environment' => $environment, 'provider_order_id' => $providerId, 'operation' => $operation,
                'http_method' => $method, 'endpoint' => $endpoint,
                'request_payload' => $payload === [] ? null : PaymentPayloadSanitizer::sanitize($payload),
                'requested_at' => now(), 'outcome' => 'started',
            ]));
        } catch (Throwable) {
            throw new PaymentGatewayUnavailableException('Pencatatan komunikasi pembayaran belum tersedia.');
        }
    }

    public function finish(PaymentCommunicationLog $log, int $started, array $data): void
    {
        try {
            $log->update(PaymentPayloadSanitizer::sanitize([
                ...$data, 'finished_at' => now(), 'duration_ms' => max(0, (int) ((hrtime(true) - $started) / 1_000_000)),
            ]));
        } catch (Throwable) {
            throw new PaymentGatewayUnavailableException('Hasil komunikasi pembayaran belum dapat dicatat. Silakan sinkronkan status.');
        }
    }
}
