<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Contracts\WarehouseStockProvider;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\PaymentOperationInProgress;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentRequest;
use App\Support\PaymentAmount;
use App\Support\PaymentMethods;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrderPayments
{
    private ?string $token = null;

    public function __construct(private readonly PaymentGateway $gateway) {}

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['success' => false, 'code' => $code, 'message' => $message, 'data' => null, 'errors' => null], 409));
    }

    private function operate(Order $order, Closure $callback): mixed
    {
        $token = (string) Str::uuid();
        DB::transaction(function () use ($order, $token): void {
            $record = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($record->payment_operation_token !== null && $record->payment_operation_started_at?->gt(now()->subMinutes(2))) {
                throw new PaymentOperationInProgress;
            }
            $record->update(['payment_operation_token' => $token, 'payment_operation_started_at' => now()]);
        });
        $this->token = $token;
        try {
            return $callback($order->fresh());
        } finally {
            Order::whereKey($order->id)->where('payment_operation_token', $token)
                ->update(['payment_operation_token' => null, 'payment_operation_started_at' => null]);
            $this->token = null;
        }
    }

    private function owned(Order $order): void
    {
        if ($this->token === null || Order::whereKey($order->id)->value('payment_operation_token') !== $this->token) {
            throw new PaymentOperationInProgress;
        }
    }

    private function environment(OrderPayment $payment): void
    {
        if ($payment->environment !== PaymentMethods::environment()) {
            throw new PaymentGatewayUnavailableException('Environment pembayaran berbeda dari konfigurasi Midtrans aktif.');
        }
    }

    public function create(Order $order, string $method, string $key): array
    {
        return $this->operate($order, function (Order $record) use ($method, $key): array {
            if ($record->payment_provider !== 'midtrans' || $record->payment_integration !== 'core_api') {
                $this->conflict('PAYMENT_INTEGRATION_MISMATCH', 'Order ini tidak diterbitkan melalui Core API.');
            }
            $request = PaymentRequest::firstOrCreate(['order_id' => $record->id, 'idempotency_key' => $key], ['method' => $method]);
            if ($request->method !== $method) {
                $this->conflict('IDEMPOTENCY_CONFLICT', 'Idempotency-Key sudah digunakan untuk metode lain.');
            }
            if ($request->payment_id !== null) {
                $payment = OrderPayment::findOrFail($request->payment_id);
                if (in_array($payment->state, ['creating', 'unknown'], true)) {
                    $this->open($record);
                    $payment = $this->recoverOrCharge($record, $payment);
                }

                return [$payment, false];
            }
            $this->open($record);
            $active = $record->activePayment;
            if ($active !== null) {
                $this->environment($active);
                if ($active->isActive()) {
                    if ($active->state === 'pending' && $active->method === $method && $active->expires_at?->isFuture()) {
                        $request->update(['payment_id' => $active->id]);

                        return [$active, false];
                    }
                    $this->synchronizeAttempt($record, $active);
                    $this->open($record->fresh());
                    if ($active->fresh()->isActive()) {
                        $this->cancelAttempt($record, $active->fresh());
                        $this->open($record->fresh());
                    }
                }
            }
            $record = $record->fresh();
            $this->open($record);
            $expires = now()->addMinutes(PaymentMethods::ttl($method))->min($record->payment_due_at);
            if ($expires->lte(now()->addSeconds(20))) {
                $this->conflict('PAYMENT_DEADLINE_REACHED', 'Waktu pembayaran terlalu singkat. Silakan checkout ulang setelah order berakhir.');
            }
            $this->owned($record);
            $payment = DB::transaction(function () use ($record, $method, $key, $expires, $request): OrderPayment {
                Order::whereKey($record->id)->lockForUpdate()->firstOrFail();
                $number = (int) $record->payments()->max('attempt_number') + 1;
                $providerId = $record->order_number.'-P'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
                $payload = $this->payload($record, $method, $providerId, $expires);
                $payment = $record->payments()->create([
                    'attempt_number' => $number, 'provider_order_id' => $providerId, 'method' => $method,
                    'environment' => PaymentMethods::environment(), 'idempotency_key' => $key,
                    'state' => 'creating', 'amount' => $record->total, 'currency' => 'IDR',
                    'request_payload' => $payload, 'expires_at' => $expires,
                ]);
                $record->update(['active_payment_id' => $payment->id]);
                $request->update(['payment_id' => $payment->id]);

                return $payment;
            });

            return [$this->charge($record, $payment), true];
        });
    }

    private function open(Order $order): void
    {
        if ($order->status !== 'pending_payment') {
            $this->conflict('ORDER_NOT_PAYABLE', 'Order sudah lunas, ditutup, atau memerlukan pemeriksaan pembayaran.');
        }
        if ($order->payment_close_reason !== null) {
            $this->conflict('PAYMENT_CLOSING', 'Penutupan pembayaran sedang diproses. Silakan sinkronkan status.');
        }
        if ($order->payment_due_at === null || $order->payment_due_at->isPast()) {
            $this->conflict('PAYMENT_DEADLINE_REACHED', 'Batas pembayaran order sudah berakhir.');
        }
    }

    private function payload(Order $order, string $method, string $providerId, Carbon $expires): array
    {
        $amount = PaymentAmount::rupiah($order->total);
        if ($amount <= 0 || $order->currency !== 'IDR') {
            $this->conflict('PAYMENT_AMOUNT_INVALID', 'Nominal dan mata uang pembayaran tidak valid.');
        }
        $items = $order->items->map(fn ($item): array => [
            'id' => mb_substr($item->sku, 0, 50), 'price' => PaymentAmount::rupiah($item->unit_price),
            'quantity' => $item->quantity, 'name' => mb_substr($item->product_name.' '.$item->variant_name, 0, 50),
        ])->all();
        $shipping = PaymentAmount::rupiah($order->shipping_fee);
        if ($shipping > 0) {
            $items[] = ['id' => 'SHIPPING', 'price' => $shipping, 'quantity' => 1, 'name' => 'Ongkos kirim'];
        }
        if (array_sum(array_map(fn ($item) => $item['price'] * $item['quantity'], $items)) !== $amount) {
            $this->conflict('PAYMENT_AMOUNT_INVALID', 'Rincian pembayaran tidak sesuai total order.');
        }
        $payload = [
            'payment_type' => PaymentMethods::type($method),
            'transaction_details' => ['order_id' => $providerId, 'gross_amount' => $amount],
            'item_details' => $items,
            'customer_details' => ['first_name' => $order->shipping_address['name'], 'phone' => $order->shipping_address['phone']],
            'custom_expiry' => ['order_time' => now()->format('Y-m-d H:i:s O'), 'expiry_duration' => max(21, $expires->timestamp - now()->timestamp), 'unit' => 'second'],
        ];
        if ($method === 'qris') {
            $payload['qris'] = ['acquirer' => config('services.midtrans.qris_acquirer', 'gopay')];
        } elseif ($method === 'mandiri_bill') {
            $payload['echannel'] = ['bill_info1' => 'Payment:', 'bill_info2' => mb_substr($order->order_number, 0, 30)];
        } else {
            $payload['bank_transfer'] = ['bank' => str_replace('_va', '', $method)];
        }

        return $payload;
    }

    private function charge(Order $order, OrderPayment $payment): OrderPayment
    {
        $this->environment($payment);
        $this->owned($order);
        try {
            $body = $this->gateway->charge($payment->request_payload);

            return $this->apply($order, $payment, $body);
        } catch (Throwable $exception) {
            OrderPayment::whereKey($payment->id)->where('state', 'creating')->update(['state' => 'unknown']);
            throw $exception;
        }
    }

    private function recoverOrCharge(Order $order, OrderPayment $payment): OrderPayment
    {
        $body = $this->fetch($order, $payment);
        if ($body !== null) {
            return $this->apply($order, $payment, $body);
        }
        if ($payment->expires_at?->isPast()) {
            // No provider transaction exists and the original payload can no longer create a payable charge.
            $payment->update(['state' => 'expired']);

            return $payment;
        }

        return $this->charge($order, $payment);
    }

    private function fetch(Order $order, OrderPayment $payment): ?array
    {
        $this->environment($payment);
        $this->owned($order);

        return $this->gateway->status($payment->provider_order_id);
    }

    private function synchronizeAttempt(Order $order, OrderPayment $payment): OrderPayment
    {
        $body = $this->fetch($order, $payment);
        if ($body === null) {
            if (in_array($payment->state, ['creating', 'unknown'], true)) {
                return $this->recoverOrCharge($order, $payment);
            }
            throw new PaymentGatewayUnavailableException('Status pembayaran belum ditemukan di Midtrans.');
        }

        return $this->apply($order, $payment, $body);
    }

    private function cancelAttempt(Order $order, OrderPayment $payment): void
    {
        $payment = $this->synchronizeAttempt($order, $payment);
        if (! $payment->isActive()) {
            return;
        }
        $this->owned($order);
        $payment->update(['state' => 'cancelling']);
        try {
            $this->gateway->cancel($payment->provider_order_id);
        } catch (PaymentGatewayUnavailableException) {
            // A timeout or rejection may mean payment won the race; resolve from canonical status below.
        }
        // Cancel response alone is insufficient when cancellation races with a payment.
        $payment = $this->synchronizeAttempt($order, $payment);
        if ($payment->isActive()) {
            throw new PaymentOperationInProgress;
        }
    }

    public function refresh(Order $order, ?int $paymentId = null): ?OrderPayment
    {
        return $this->operate($order, function (Order $record) use ($paymentId): ?OrderPayment {
            $payment = $paymentId ? $record->payments()->findOrFail($paymentId) : $record->activePayment;
            if ($payment === null && $record->payment_provider === 'midtrans' && $record->payment_integration !== 'core_api') {
                $body = $this->gateway->status($record->order_number);
                if ($body !== null) {
                    $payment = $record->payments()->create([
                        'attempt_number' => 1, 'provider_order_id' => $record->order_number, 'method' => 'legacy',
                        'environment' => PaymentMethods::environment(), 'idempotency_key' => 'legacy-import',
                        'state' => 'pending', 'amount' => $record->total, 'request_payload' => [],
                    ]);
                    $record->update(['active_payment_id' => $payment->id]);

                    return $this->apply($record, $payment, $body);
                }
            }
            if ($payment !== null) {
                $payment = $this->synchronizeAttempt($record, $payment);
            }
            $record = $record->fresh();
            if ($record->status === 'pending_payment' && ($record->payment_close_reason !== null || $record->payment_due_at?->isPast())) {
                $this->close($record, $record->payment_close_reason ?? 'expired');
                $payment = $payment?->fresh();
            }
            $record->update(['payment_last_synced_at' => now()]);

            return $payment;
        });
    }

    public function cancel(Order $order): Order
    {
        return $this->operate($order, function (Order $record): Order {
            if ($record->status === 'cancelled') {
                return $record;
            }
            if ($record->status !== 'pending_payment') {
                $this->conflict('ORDER_NOT_CANCELLABLE', 'Order ini sudah lunas atau tidak dapat dibatalkan.');
            }
            if ($record->activePayment) {
                $this->synchronizeAttempt($record, $record->activePayment);
            } elseif ($record->payment_integration !== 'core_api') {
                $body = $this->gateway->status($record->order_number);
                if ($body !== null) {
                    $this->conflict('LEGACY_PAYMENT_REQUIRES_REFRESH', 'Sinkronkan pembayaran lama sebelum membatalkan order.');
                }
            }
            $record = $record->fresh();
            if ($record->status !== 'pending_payment') {
                $this->conflict('ORDER_NOT_CANCELLABLE', 'Pembayaran telah diterima. Order tidak dapat dibatalkan.');
            }
            $this->close($record, 'cancelled');

            $closed = $record->fresh();
            if ($closed->status !== 'cancelled') {
                $this->conflict('ORDER_NOT_CANCELLABLE', 'Pembayaran telah diterima atau membutuhkan pemeriksaan.');
            }

            return $closed;
        });
    }

    private function close(Order $order, string $reason): void
    {
        $this->owned($order);
        $order->update(['payment_close_reason' => $reason]);
        if ($order->activePayment?->isActive()) {
            $this->cancelAttempt($order, $order->activePayment);
        }
        $order = $order->fresh();
        if ($order->status !== 'pending_payment') {
            return;
        }
        DB::transaction(function () use ($order, $reason): void {
            $record = Order::lockForUpdate()->findOrFail($order->id);
            if ($record->stock_reservation_id !== null) {
                app(WarehouseStockProvider::class)->release($record->stock_reservation_id);
            }
            $record->update(['stock_reservation_id' => null]);
            $this->transition($record, $reason, 'Pembayaran dan order telah ditutup.');
        });
    }

    private function apply(Order $order, OrderPayment $payment, array $body): OrderPayment
    {
        $this->owned($order);
        try {
            $amount = PaymentAmount::rupiah($body['gross_amount'] ?? 'invalid');
        } catch (Throwable) {
            throw new PaymentGatewayUnavailableException('Nominal respons Midtrans tidak valid.');
        }
        if (($body['order_id'] ?? null) !== $payment->provider_order_id || $amount !== PaymentAmount::rupiah($payment->amount)
            || ($body['currency'] ?? null) !== 'IDR' || blank($body['transaction_id'] ?? null)
            || ($payment->transaction_id !== null && $payment->transaction_id !== $body['transaction_id'])) {
            throw new PaymentGatewayUnavailableException('Identitas atau nominal transaksi Midtrans tidak sesuai.');
        }
        if ($payment->method !== 'legacy' && ($body['payment_type'] ?? null) !== PaymentMethods::type($payment->method)) {
            throw new PaymentGatewayUnavailableException('Metode transaksi Midtrans tidak sesuai.');
        }
        $status = strtolower((string) ($body['transaction_status'] ?? ''));
        // Get Status may omit VA/QR instructions; retain instructions issued by the verified charge response.
        $body = array_replace($payment->response_payload ?? [], $body);
        if (! in_array($status, ['pending', 'settlement', 'capture', 'cancel', 'expire', 'deny', 'failure', 'refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
            throw new PaymentGatewayUnavailableException('Status transaksi Midtrans belum didukung.');
        }
        if ($status === 'pending') {
            $valid = match ($payment->method) {
                'qris' => collect($body['actions'] ?? [])->contains(fn ($action) => in_array($action['name'] ?? null, ['generate-qr-code', 'generate-qr-code-v2'], true) && is_string($action['url'] ?? null)),
                'mandiri_bill' => is_string($body['bill_key'] ?? null) && is_string($body['biller_code'] ?? null),
                'permata_va' => is_string($body['permata_va_number'] ?? null) || filled($body['va_numbers'][0]['va_number'] ?? null),
                'legacy' => true,
                default => is_string($body['va_numbers'][0]['va_number'] ?? null)
                    && ($body['va_numbers'][0]['bank'] ?? null) === str_replace('_va', '', $payment->method),
            };
            if (! $valid) {
                throw new PaymentGatewayUnavailableException('Instruksi pembayaran Midtrans belum lengkap.');
            }
        }

        return DB::transaction(function () use ($order, $payment, $body, $status): OrderPayment {
            $record = Order::lockForUpdate()->findOrFail($order->id);
            $this->owned($record);
            $payment = OrderPayment::lockForUpdate()->findOrFail($payment->id);
            $paid = in_array($status, ['settlement', 'capture'], true)
                && (string) ($body['status_code'] ?? '') === '200'
                && (! isset($body['fraud_status']) || $body['fraud_status'] === 'accept')
                && ($status !== 'capture' || ($body['fraud_status'] ?? null) === 'accept');
            if ($payment->state === 'paid' && $status === 'pending') {
                return $payment;
            }
            $wasPaid = $payment->paid_at !== null;
            $state = $paid ? 'paid' : match ($status) {
                'pending' => $payment->state === 'cancelling' ? 'cancelling' : 'pending', 'expire' => 'expired', 'cancel' => 'cancelled', 'deny' => 'denied', 'failure' => 'failed',
                'refund' => 'refunded', default => 'review',
            };
            $data = Arr::only($body, ['order_id', 'transaction_id', 'gross_amount', 'currency', 'status_code', 'status_message',
                'transaction_status', 'payment_type', 'fraud_status', 'va_numbers', 'permata_va_number', 'bill_key', 'biller_code',
                'actions', 'expiry_time', 'settlement_time', 'refund_amount']);
            $confirmedAt = now();
            if (isset($body['settlement_time']) && is_string($body['settlement_time'])) {
                try {
                    $confirmedAt = Carbon::parse($body['settlement_time'], 'Asia/Jakarta');
                } catch (Throwable) {
                }
            }
            $expires = $payment->expires_at;
            if (isset($body['expiry_time']) && is_string($body['expiry_time'])) {
                try {
                    $providerExpiry = Carbon::parse($body['expiry_time'], 'Asia/Jakarta');
                    $expires = $expires ? $expires->min($providerExpiry) : $providerExpiry;
                } catch (Throwable) {
                }
            }
            $payment->update(['expires_at' => $expires, 'transaction_id' => $body['transaction_id'], 'state' => $state, 'transaction_status' => $status,
                'response_payload' => $data, 'paid_at' => $paid ? ($payment->paid_at ?? $confirmedAt) : $payment->paid_at, 'last_synced_at' => now()]);
            $multiple = $record->payments()->where('state', 'paid')->count() > 1;
            $late = $paid && (in_array($record->status, ['cancelled', 'expired', 'refunded'], true)
                || $record->active_payment_id !== $payment->id || ($record->payment_due_at && $confirmedAt->gt($record->payment_due_at)));
            if ($multiple || $late || $state === 'review' || ($wasPaid && in_array($status, ['deny', 'cancel', 'failure'], true))) {
                $this->transition($record, 'payment_review', 'Transaksi memerlukan pemeriksaan: pembayaran ganda, terlambat, reversal, atau status khusus.');
            } elseif ($paid && $record->status === 'pending_payment') {
                if ($record->stock_reservation_id !== null) {
                    app(WarehouseStockProvider::class)->confirm($record->stock_reservation_id);
                }
                $record->update(['stock_reservation_id' => null, 'paid_at' => $record->paid_at ?? now(), 'payment_reference' => $payment->transaction_id]);
                $this->transition($record, 'paid', 'Pembayaran dikonfirmasi melalui Midtrans Core API.');
            } elseif ($status === 'refund' && $record->payments()->where('state', 'paid')->doesntExist() && in_array($record->status, ['paid', 'payment_review'], true)) {
                $this->transition($record, 'refunded', 'Pengembalian pembayaran dikonfirmasi Midtrans.');
            }

            return $payment->fresh();
        });
    }

    private function transition(Order $order, string $status, string $note): void
    {
        if ($order->status === $status) {
            return;
        }
        $order->update(['status' => $status]);
        $order->statusHistories()->create(['status' => $status, 'note' => $note]);
    }
}
