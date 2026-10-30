<?php

namespace App\Services\Marketplace;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StoreRegistrationService
{
    /**
     * Create the seller identity and its store in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(User $user, array $data): Store
    {
        if (! $user->isWalatraMember()) {
            throw ValidationException::withMessages([
                'user' => 'Hanya member Walatra aktif yang dapat mendaftarkan toko.',
            ]);
        }

        if ($user->store()->exists() || $user->managedStores()->exists()) {
            throw ValidationException::withMessages([
                'store' => 'Akun ini sudah memiliki atau terhubung dengan toko.',
            ]);
        }

        $email = strtolower((string) $data['email']);

        if (filled($user->email) && strtolower((string) $user->email) !== $email) {
            throw ValidationException::withMessages([
                'email' => 'Email akun yang sudah digunakan tidak dapat diganti melalui pendaftaran toko.',
            ]);
        }

        return DB::transaction(function () use ($data, $email, $user): Store {
            $user->forceFill([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
            ])->save();

            Role::findOrCreate('store_admin', 'web');
            $user->assignRole('store_admin');

            $store = Store::query()->create([
                'owner_user_id' => $user->id,
                'name' => $data['store_name'] ?? $data['name'],
                'description' => $data['description'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? $user->phone,
                'contact_email' => $data['contact_email'] ?? $email,
                'province' => $data['province'] ?? null,
                'city' => $data['city'] ?? null,
                'district' => $data['district'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'status' => 'pending_approval',
            ]);

            $store->admins()->syncWithoutDetaching([
                $user->id => ['role' => 'owner', 'status' => 'active'],
            ]);

            return $store;
        });
    }
}
