<?php

namespace App\Services\Auth;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class OtpService
{
    public function issue(
        string $phone,
        string $purpose,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $phone = PhoneNumber::normalize($phone);

        $plainCode = (string) random_int(100000, 999999);
        $codeHash = Hash::make($plainCode);

        $challenge = DB::transaction(function () use ($phone, $purpose, $codeHash, $ip, $userAgent): OtpChallenge {
            OtpChallenge::query()
                ->forPhone($phone, $purpose)
                ->whereNull('verified_at')
                ->whereNull('invalidated_at')
                ->update([
                    'invalidated_at' => now(),
                ]);

            return OtpChallenge::query()->create([
                'phone' => $phone,
                'purpose' => $purpose,
                'code_hash' => $codeHash,
                'expires_at' => Carbon::now()->addMinutes(5),
                'attempts' => 0,
                'max_attempts' => 5,
                'request_ip' => $ip,
                'user_agent' => $userAgent,
            ]);
        });

        return [
            'challenge' => $challenge,
            'plain_code' => $plainCode,
        ];
    }

    public function verify(
        string $phone,
        int $challengeId,
        string $otp,
        string $deviceName,
    ): array {
        $phone = PhoneNumber::normalize($phone);

        $result = DB::transaction(function () use (
            $phone,
            $challengeId,
            $otp,
            $deviceName,
        ): ?array {
            $challenge = OtpChallenge::query()
                ->whereKey($challengeId)
                ->forPhone($phone, 'customer_login')
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->isUsable()) {
                throw ValidationException::withMessages([
                    'otp' => 'OTP tidak valid atau sudah tidak dapat digunakan.',
                ]);
            }

            if (! Hash::check($otp, $challenge->code_hash)) {
                $challenge->increment('attempts');

                if ($challenge->hasExceededAttempts()) {
                    $challenge->forceFill(['invalidated_at' => now()])->save();
                }

                // Commit the failed attempt before returning a validation error.
                return null;
            }

            // Serialize first-time account creation for the same phone number.
            // PostgreSQL advisory locks are released automatically at transaction end.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select(
                    'select pg_advisory_xact_lock(hashtextextended(?, 0))',
                    [$phone],
                );
            }

            $user = User::query()
                ->where('phone', $phone)
                ->lockForUpdate()
                ->first();

            if ($user && ($user->user_type !== 'customer' || $user->status !== 'active')) {
                throw ValidationException::withMessages([
                    'phone' => 'Akun customer tidak tersedia atau tidak aktif.',
                ]);
            }

            $isNewUser = false;

            if (! $user) {
                $user = User::query()->create([
                    'name' => null,
                    'phone' => $phone,
                    'user_type' => 'customer',
                    'status' => 'active',
                    'profile_completed_at' => null,
                ]);
                $isNewUser = true;
            }

            if (! $user->hasRole('customer')) {
                Role::findOrCreate('customer', 'web');
                $user->assignRole('customer');
            }

            $challenge->forceFill([
                'verified_at' => now(),
            ])->save();

            $token = $user->createToken(
                $deviceName,
                ['customer-api'],
            );

            return [
                'user' => $user,
                'token' => $token->plainTextToken,
                'is_new_user' => $isNewUser,
                'requires_profile_completion' => $user->profile_completed_at === null,
            ];
        });

        if ($result === null) {
            throw ValidationException::withMessages([
                'otp' => 'OTP tidak valid atau sudah tidak dapat digunakan.',
            ]);
        }

        return $result;
    }
}
