<?php

namespace App\Services\Auth;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function issue(
        string $phone,
        string $purpose,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $phone = PhoneNumber::normalize($phone);

        OtpChallenge::query()
            ->forPhone($phone, $purpose)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->update([
                'invalidated_at' => now(),
            ]);

        $plainCode = (string) random_int(100000, 999999);

        $challenge = OtpChallenge::query()->create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($plainCode),
            'expires_at' => Carbon::now()->addMinutes(5),
            'attempts' => 0,
            'max_attempts' => 5,
            'request_ip' => $ip,
            'user_agent' => $userAgent,
        ]);

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

        return DB::transaction(function () use (
            $phone,
            $challengeId,
            $otp,
            $deviceName,
        ): array {
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

                throw ValidationException::withMessages([
                    'otp' => 'OTP tidak valid atau sudah tidak dapat digunakan.',
                ]);
            }

            $user = User::query()
                ->where('phone', $phone)
                ->where('user_type', 'customer')
                ->where('status', 'active')
                ->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'phone' => 'Nomor belum terdaftar sebagai customer.',
                ]);
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
            ];
        });
    }
}
