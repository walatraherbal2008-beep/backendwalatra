<?php

namespace App\Services\Marketplace;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualOrderService
{
    public function confirmPaid(Order $order, User $actor, string $note, ?string $reference = null): Order
    {
        return $this->transition($order, $actor, 'paid', $note, $reference);
    }

    public function cancel(Order $order, User $actor, string $note): Order
    {
        return $this->transition($order, $actor, 'cancelled', $note);
    }

    private function transition(Order $order, User $actor, string $status, string $note, ?string $reference = null): Order
    {
        abort_unless($actor->status === 'active' && $actor->hasRole('super_admin'), 403);
        $note = trim($note);

        if (mb_strlen($note) < 5 || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi antara 5 dan 1.000 karakter.']);
        }

        if ($reference !== null && mb_strlen($reference) > 255) {
            throw ValidationException::withMessages(['reference' => 'Referensi pembayaran maksimal 255 karakter.']);
        }

        return DB::transaction(function () use ($order, $actor, $status, $note, $reference): Order {
            $record = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($record->payment_provider !== 'manual' || $record->status !== 'pending_payment') {
                throw ValidationException::withMessages(['note' => 'Hanya order manual yang menunggu pembayaran dapat diproses.']);
            }

            $record->update([
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
                'payment_reference' => $status === 'paid' ? (filled($reference) ? trim($reference) : null) : null,
            ]);
            $record->statusHistories()->create([
                'status' => $status,
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            return $record->fresh();
        });
    }
}
