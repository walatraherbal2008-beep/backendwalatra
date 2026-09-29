<?php

namespace App\Services\Marketplace;

use App\Models\Store;
use App\Models\StoreOwnerHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreOwnerAssignmentService
{
    public function assign(Store $store, User $owner, ?User $changedBy = null, ?string $reason = null): Store
    {
        if (! $owner->isWalatraMember()
            || $owner->email_verified_at === null
            || ! $owner->hasRole('store_admin')
        ) {
            throw ValidationException::withMessages([
                'owner_user_id' => 'Pemilik toko harus member Walatra aktif, terverifikasi, dan memiliki role store_admin.',
            ]);
        }

        $alreadyOwnsAnotherStore = Store::query()
            ->where('owner_user_id', $owner->id)
            ->whereKeyNot($store->id)
            ->exists();

        if ($alreadyOwnsAnotherStore) {
            throw ValidationException::withMessages([
                'owner_user_id' => 'Member tersebut sudah memiliki toko lain.',
            ]);
        }

        return DB::transaction(function () use ($store, $owner, $changedBy, $reason): Store {
            $previousOwnerId = $store->owner_user_id;

            $store->update([
                'owner_user_id' => $owner->id,
                'status' => $store->status === 'pending_owner' ? 'pending_approval' : $store->status,
            ]);

            if ($previousOwnerId !== $owner->id) {
                StoreOwnerHistory::query()->create([
                    'store_id' => $store->id,
                    'previous_owner_user_id' => $previousOwnerId,
                    'new_owner_user_id' => $owner->id,
                    'changed_by' => $changedBy?->id,
                    'reason' => $reason,
                ]);
            }

            return $store->fresh('owner');
        });
    }

    public function isValidOwner(?User $owner): bool
    {
        return $owner !== null
            && $owner->isWalatraMember()
            && $owner->email_verified_at !== null
            && $owner->hasRole('store_admin');
    }
}
