<?php

namespace App\Services\Marketplace;

use App\Models\User;
use App\Notifications\CustomerAccountStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class UserStatusService
{
    /**
     * @param  'active'|'suspended'|'blocked'|'inactive'  $status
     */
    public function change(User $user, string $status, string $reason, User $changedBy): User
    {
        if (! in_array($status, ['active', 'suspended', 'blocked', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Status akun tidak valid.']);
        }

        if ($user->id === $changedBy->id) {
            throw ValidationException::withMessages(['user' => 'Admin tidak dapat mengubah status akunnya sendiri.']);
        }

        if ($user->user_type !== 'customer') {
            throw ValidationException::withMessages(['user' => 'Hanya akun customer yang dapat dikelola melalui modul ini.']);
        }

        $updated = DB::transaction(function () use ($user, $status, $reason, $changedBy): User {
            $previousStatus = $user->status;

            $user->forceFill([
                'status' => $status,
                'status_changed_at' => now(),
                'status_reason' => $reason,
                'status_changed_by' => $changedBy->id,
            ])->save();

            $user->statusHistories()->create([
                'previous_status' => $previousStatus,
                'new_status' => $status,
                'reason' => $reason,
                'changed_by' => $changedBy->id,
            ]);

            if ($status !== 'active') {
                $user->tokens()->delete();
            }

            return $user->fresh();
        });

        if (filled($updated->email)) {
            Notification::route('mail', $updated->email)
                ->notify(new CustomerAccountStatusChanged($status, $reason));
        }

        return $updated;
    }
}
