<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\AcceptStoreAdminInvitationRequest;
use App\Http\Requests\Api\V1\CreateSelfServiceStoreRequest;
use App\Http\Requests\Api\V1\CreateStoreAdminInvitationRequest;
use App\Models\MarketplaceSetting;
use App\Models\Store;
use App\Models\StoreAdminInvitation;
use App\Models\User;
use App\Notifications\StoreAdminInvitationNotification;
use App\Notifications\VerifyStoreAdminEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StoreOnboardingController
{
    public function registerStore(CreateSelfServiceStoreRequest $request): JsonResponse
    {
        if (! MarketplaceSetting::enabled('self_service_store_registration_enabled')) {
            return response()->json([
                'success' => false,
                'code' => 'SELF_SERVICE_STORE_REGISTRATION_DISABLED',
                'message' => 'Pendaftaran toko mandiri sedang ditutup.',
            ], 403);
        }

        /** @var User $user */
        $user = $request->user();

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

        $data = $request->validated();
        if (filled($user->email) && strtolower((string) $user->email) !== strtolower($data['email'])) {
            throw ValidationException::withMessages([
                'email' => 'Email akun yang sudah digunakan tidak dapat diganti melalui pendaftaran toko.',
            ]);
        }
        $tokenUrl = null;

        $store = DB::transaction(function () use ($data, $user, &$tokenUrl): Store {
            $user->forceFill([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
            ])->save();

            Role::findOrCreate('store_admin', 'web');
            $user->assignRole('store_admin');

            $store = Store::query()->create([
                'owner_user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? $user->phone,
                'contact_email' => $data['contact_email'] ?? $data['email'],
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

            $tokenUrl = URL::temporarySignedRoute(
                'store-onboarding.verify-email',
                now()->addDay(),
                ['user' => $user->id, 'hash' => sha1((string) $user->email)],
            );

            return $store;
        });

        Notification::route('mail', $user->email)
            ->notify(new VerifyStoreAdminEmailNotification($tokenUrl));

        return response()->json([
            'success' => true,
            'code' => 'STORE_REGISTRATION_SUBMITTED',
            'message' => 'Pendaftaran toko berhasil dikirim dan menunggu verifikasi serta persetujuan.',
            'data' => [
                'store' => $store->only(['id', 'name', 'code', 'status']),
                'email_verification_required' => true,
            ],
        ], 201);
    }

    public function verifyEmail(Request $request, User $user, string $hash): JsonResponse
    {
        abort_unless(hash_equals(sha1((string) $user->email), $hash), 403);

        $user->forceFill(['email_verified_at' => now()])->save();

        return response()->json([
            'success' => true,
            'code' => 'EMAIL_VERIFIED',
            'message' => 'Email berhasil diverifikasi. Toko masih menunggu persetujuan Superadmin.',
        ]);
    }

    public function inviteAdmin(CreateStoreAdminInvitationRequest $request): JsonResponse
    {
        if (! MarketplaceSetting::enabled('self_service_store_admin_invitation_enabled')) {
            return response()->json([
                'success' => false,
                'code' => 'SELF_SERVICE_ADMIN_INVITATION_DISABLED',
                'message' => 'Undangan admin toko sedang ditutup.',
            ], 403);
        }

        /** @var User $inviter */
        $inviter = $request->user();
        $store = $inviter->activeManagedStore();

        if (! $store || ! $inviter->hasRole('store_admin')) {
            throw ValidationException::withMessages([
                'user' => 'Akun tidak memiliki akses admin toko aktif.',
            ]);
        }

        $email = strtolower($request->string('email')->toString());

        if ($email === strtolower((string) $inviter->email)) {
            throw ValidationException::withMessages([
                'email' => 'Email undangan tidak boleh sama dengan email pengundang.',
            ]);
        }

        if (StoreAdminInvitation::query()
            ->where('store_id', $store->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Undangan aktif untuk email tersebut sudah tersedia.',
            ]);
        }

        $plainToken = Str::random(64);
        $invitation = StoreAdminInvitation::query()->create([
            'store_id' => $store->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $url = URL::route('store-admin-invitations.accept', ['token' => $plainToken]);
        Notification::route('mail', $email)
            ->notify(new StoreAdminInvitationNotification($store->name, $url));

        $data = [
            'invitation_id' => $invitation->id,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ];

        if (app()->environment(['local', 'testing'])) {
            $data['token'] = $plainToken;
        }

        return response()->json([
            'success' => true,
            'code' => 'STORE_ADMIN_INVITATION_CREATED',
            'message' => 'Undangan admin toko berhasil dikirim.',
            'data' => $data,
        ], 201);
    }

    public function showInvitation(string $token): JsonResponse
    {
        $invitation = StoreAdminInvitation::query()
            ->with('store:id,name,code,status')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation || ! $invitation->isUsable() || $invitation->store?->status !== 'active') {
            return response()->json([
                'success' => false,
                'code' => 'STORE_ADMIN_INVITATION_INVALID',
                'message' => 'Undangan tidak valid atau sudah kedaluwarsa.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'code' => 'STORE_ADMIN_INVITATION_AVAILABLE',
            'message' => 'Undangan tersedia. Login melalui OTP lalu kirim token untuk menerimanya.',
            'data' => [
                'token' => $token,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'store' => $invitation->store->only(['id', 'name', 'code']),
            ],
        ]);
    }

    public function acceptInvitation(AcceptStoreAdminInvitationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $email = strtolower($data['email']);

        if (filled($user->email) && strtolower((string) $user->email) !== $email) {
            throw ValidationException::withMessages([
                'email' => 'Email akun yang sudah digunakan tidak dapat diganti melalui undangan.',
            ]);
        }

        $result = DB::transaction(function () use ($data, $email, $user): array {
            $invitation = StoreAdminInvitation::query()
                ->where('token_hash', hash('sha256', $data['token']))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isUsable() || strtolower($invitation->email) !== $email) {
                throw ValidationException::withMessages([
                    'token' => 'Undangan tidak valid, sudah digunakan, atau sudah kedaluwarsa.',
                ]);
            }

            $invitation->load('store');
            if ($invitation->store->status !== 'active') {
                throw ValidationException::withMessages([
                    'token' => 'Toko tujuan undangan belum aktif.',
                ]);
            }

            $existingStore = $user->activeManagedStore();
            if ($existingStore && $existingStore->id !== $invitation->store_id) {
                throw ValidationException::withMessages([
                    'user' => 'Akun sudah memiliki akses ke toko lain.',
                ]);
            }

            $user->forceFill([
                'name' => $data['name'],
                'email' => $email,
                'password' => $data['password'],
                'email_verified_at' => now(),
            ])->save();

            Role::findOrCreate('store_admin', 'web');
            $user->assignRole('store_admin');
            $invitation->store->admins()->syncWithoutDetaching([
                $user->id => [
                    'role' => 'admin',
                    'status' => 'active',
                    'invited_by' => $invitation->invited_by,
                ],
            ]);
            $invitation->forceFill([
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ])->save();

            return ['store' => $invitation->store->only(['id', 'name', 'code'])];
        });

        return response()->json([
            'success' => true,
            'code' => 'STORE_ADMIN_INVITATION_ACCEPTED',
            'message' => 'Undangan diterima. Akun sekarang memiliki akses admin toko.',
            'data' => $result,
        ]);
    }
}
