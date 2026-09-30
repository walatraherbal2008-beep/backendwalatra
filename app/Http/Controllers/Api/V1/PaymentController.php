<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentGateway;
use App\Contracts\WarehouseStockProvider;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function create(
        Request $request,
        int $order,
        PaymentGateway $gateway,
    ): JsonResponse {
        $record = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending_payment')
            ->with('items')
            ->findOrFail($order);

        $payment = $gateway->createPayment($record);

        $record->update([
            'payment_reference' => $payment['payment_reference'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran berhasil disiapkan.',
            'data' => [
                'order_id' => $record->id,
                'order_number' => $record->order_number,
                'payment_token' => $payment['payment_token'],
                'payment_url' => $payment['payment_url'],
            ],
            'code' => 'PAYMENT_CREATED',
        ]);
    }

    public function webhook(
        Request $request,
        WarehouseStockProvider $warehouse,
    ): JsonResponse {
        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:40'],
            'status_code' => ['required', 'string', 'max:10'],
            'gross_amount' => ['required', 'string', 'max:30'],
            'signature_key' => ['required', 'string', 'size:128'],
            'transaction_status' => ['required', 'string', 'max:30'],
            'fraud_status' => ['nullable', 'string', 'max:30'],
        ]);

        $expectedSignature = hash(
            'sha512',
            $validated['order_id'].$validated['status_code'].$validated['gross_amount'].config('services.midtrans.server_key'),
        );

        if (
            blank(config('services.midtrans.server_key'))
            || ! hash_equals($expectedSignature, $validated['signature_key'])
        ) {
            throw ValidationException::withMessages([
                'signature_key' => 'Signature pembayaran tidak valid.',
            ]);
        }

        $record = Order::query()
            ->where('order_number', $validated['order_id'])
            ->lockForUpdate()
            ->firstOrFail();
        $transactionStatus = $validated['transaction_status'];

        if (number_format((float) $record->total, 2, '.', '') !== $validated['gross_amount']) {
            throw ValidationException::withMessages([
                'gross_amount' => 'Nominal pembayaran tidak sesuai dengan order.',
            ]);
        }

        $isSuccessfulCapture = $transactionStatus === 'capture'
            && ($validated['fraud_status'] ?? null) !== 'deny';

        if ($transactionStatus === 'settlement' || $isSuccessfulCapture) {
            if ($record->status !== 'paid') {
                if ($record->stock_reservation_id !== null) {
                    $warehouse->confirm($record->stock_reservation_id);
                }

                $this->transition($record, 'paid', 'Pembayaran berhasil dikonfirmasi.');
                $record->update(['stock_reservation_id' => null]);
            }
        } elseif (in_array($transactionStatus, ['deny', 'cancel', 'expire', 'failure'], true)) {
            if ($record->status === 'pending_payment') {
                if ($record->stock_reservation_id !== null) {
                    $warehouse->release($record->stock_reservation_id);
                }

                $this->transition(
                    $record,
                    $transactionStatus === 'expire' ? 'expired' : 'cancelled',
                    'Pembayaran gagal atau kedaluwarsa.',
                );
                $record->update(['stock_reservation_id' => null]);
            }
        } elseif ($transactionStatus === 'refund' && $record->status === 'paid') {
            $this->transition($record, 'refunded', 'Pembayaran dikembalikan.');
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook diterima.',
            'code' => 'PAYMENT_WEBHOOK_ACCEPTED',
            'data' => null,
        ]);
    }

    private function transition(Order $order, string $status, string $note): void
    {
        DB::transaction(function () use ($order, $status, $note): void {
            $order->update(['status' => $status]);
            $order->statusHistories()->create([
                'status' => $status,
                'note' => $note,
            ]);
        });
    }
}
