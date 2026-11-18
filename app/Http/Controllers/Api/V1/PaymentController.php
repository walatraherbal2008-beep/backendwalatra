<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderPaymentResource;
use App\Jobs\ProcessMidtransNotification;
use App\Models\Order;
use App\Models\PaymentNotification;
use App\Services\ExternalApiActivityLogger;
use App\Services\Payments\OrderPayments;
use App\Support\PaymentMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentController extends Controller
{
    private function order(Request $request, int $id): Order
    {
        return Order::where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function methods(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => array_map(fn (string $method): array => [
            'code' => $method, 'name' => PaymentMethods::LABELS[$method], 'payment_type' => PaymentMethods::type($method),
            'expiry_minutes' => PaymentMethods::ttl($method),
        ], PaymentMethods::enabled())]);
    }

    public function create(Request $request, int $order, OrderPayments $payments): JsonResponse
    {
        $record = $this->order($request, $order);
        if ($record->payment_provider === 'manual' || ($record->payment_provider === 'simulation' && $record->status === 'paid')) {
            abort_unless(in_array($record->status, ['pending_payment', 'paid'], true), 404);
            $manual = $record->payment_provider === 'manual';

            return response()->json(['success' => true,
                'message' => $manual ? 'Pembayaran menunggu verifikasi admin.' : 'Order simulasi sudah dianggap dibayar.',
                'code' => $manual ? ($record->status === 'paid' ? 'MANUAL_PAYMENT_CONFIRMED' : 'MANUAL_PAYMENT_REQUIRED') : 'PAYMENT_SIMULATED',
                'data' => ['order_id' => $record->id, 'order_number' => $record->order_number, 'payment_provider' => $record->payment_provider,
                    'payment_required' => $record->status === 'pending_payment', 'simulation' => ! $manual,
                    'instructions' => $manual && $record->status === 'pending_payment' ? config('checkout.manual.payment_instructions') : null,
                    'payment_token' => null, 'payment_url' => null],
            ]);
        }
        $validated = $request->validate(['method' => ['required', 'string', Rule::in(PaymentMethods::enabled())]]);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            return response()->json(['success' => false, 'message' => 'Idempotency-Key wajib diisi, maksimal 100 karakter.', 'code' => 'VALIDATION_ERROR'], 422);
        }
        [$payment, $created] = $payments->create($record, $validated['method'], $key);

        return $this->response($record->fresh(), $payment, $created ? 201 : 200);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $record = $this->order($request, $order);

        return $this->response($record, $record->activePayment);
    }

    public function refresh(Request $request, int $order, OrderPayments $payments): JsonResponse
    {
        $record = $this->order($request, $order);
        abort_unless($record->payment_provider === 'midtrans', 404);
        $payment = $payments->refresh($record);

        return $this->response($record->fresh(), $payment);
    }

    private function response(Order $order, $payment, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'code' => 'PAYMENT_READY', 'message' => 'Informasi pembayaran berhasil diambil.',
            'data' => ['order_id' => $order->id, 'order_number' => $order->order_number, 'order_status' => $order->status,
                'payment_due_at' => $order->payment_due_at?->toIso8601String(),
                'payment_required' => $order->status === 'pending_payment' && $order->payment_close_reason === null && ($order->payment_due_at === null || $order->payment_due_at->isFuture()),
                'payment_token' => null, 'payment_url' => null, 'payment' => $payment ? new OrderPaymentResource($payment) : null],
        ], $status);
    }

    public function qrCode(Request $request, int $order, int $payment, PaymentGateway $gateway): Response
    {
        $record = $this->order($request, $order);
        $attempt = $record->payments()->findOrFail($payment);
        abort_unless($attempt->id === $record->active_payment_id && $attempt->method === 'qris', 404);
        abort_unless($record->status === 'pending_payment' && $record->payment_close_reason === null
            && $record->payment_due_at?->isFuture() && $attempt->state === 'pending' && $attempt->expires_at?->isFuture(), 410);
        abort_unless($attempt->environment === PaymentMethods::environment(), 409);
        $actions = collect($attempt->response_payload['actions'] ?? []);
        $action = $actions->firstWhere('name', 'generate-qr-code-v2') ?? $actions->firstWhere('name', 'generate-qr-code');
        abort_unless(is_string($action['url'] ?? null), 404);

        return response($gateway->qrImage($action['url']), 200)->header('Content-Type', 'image/png')->header('Cache-Control', 'no-store');
    }

    public function webhook(Request $request): JsonResponse
    {
        $activityLogger = app(ExternalApiActivityLogger::class);
        $started = hrtime(true);
        $activity = null;

        try {
            $data = $request->validate([
                'order_id' => ['required', 'string', 'max:100'], 'status_code' => ['required', 'string', 'max:10'],
                'gross_amount' => ['required', 'string', 'max:30'], 'signature_key' => ['required', 'string', 'size:128'],
                'transaction_status' => ['required', 'string', 'max:30'], 'transaction_id' => ['sometimes', 'string', 'max:100'],
                'fraud_status' => ['nullable', 'string', 'max:30'],
            ]);
            $key = (string) config('services.midtrans.server_key');
            $signature = hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].$key);
            if ($key === '' || ! hash_equals($signature, $data['signature_key'])) {
                return response()->json(['success' => false, 'code' => 'INVALID_SIGNATURE', 'message' => 'Signature pembayaran tidak valid.'], 403);
            }
            $activity = $activityLogger->start('midtrans', 'Webhook notifikasi pembayaran', $request->method(), url('/api/v1/payments/midtrans/webhook'), $data);
            ksort($data);
            $hash = hash('sha256', PaymentMethods::environment().json_encode($data, JSON_THROW_ON_ERROR));
            $notification = DB::transaction(fn () => PaymentNotification::firstOrCreate(['event_hash' => $hash], [
                'provider_order_id' => $data['order_id'], 'environment' => PaymentMethods::environment(), 'payload' => $data,
            ]));
            if ($notification->processed_at === null) {
                try {
                    ProcessMidtransNotification::dispatch($notification->id);
                } catch (Throwable) {
                    // The durable inbox is retried by payments:reconcile even when the queue is unavailable.
                }
            }

            $result = ['success' => true, 'message' => 'Webhook diterima.', 'code' => 'PAYMENT_WEBHOOK_ACCEPTED', 'data' => null];
            $activityLogger->finish($activity, $started, 'succeeded', 200, $result);

            return response()->json($result);
        } catch (Throwable $exception) {
            $activityLogger->finish(
                $activity,
                $started,
                $activityLogger->exceptionStatus($exception),
                $exception instanceof ValidationException ? 422 : null,
                errorMessage: 'Webhook Midtrans tidak dapat diproses.',
            );

            throw $exception;
        }
    }
}
