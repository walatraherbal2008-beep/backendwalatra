<?php

namespace App\Providers;

use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $throttledResponse = function (Request $request, array $headers): JsonResponse {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan. Silakan tunggu sebelum mencoba lagi.',
                'retry_after' => (int) ($headers['Retry-After'] ?? 0),
            ], 429, $headers);
        };

        RateLimiter::for('otp-request', function (Request $request) use ($throttledResponse): array {
            $rawPhone = $request->input('phone');
            $rawPhone = is_string($rawPhone) && strlen($rawPhone) <= 20 ? $rawPhone : '';

            try {
                $phoneKey = PhoneNumber::normalize($rawPhone);
            } catch (InvalidArgumentException) {
                $phoneKey = 'invalid:'.hash('sha256', $rawPhone);
            }

            return [
                // perMinute(maximum requests, duration in minutes).
                Limit::perMinute(10, 1)
                    ->by('phone:'.$phoneKey)
                    ->response($throttledResponse),
                Limit::perMinute(5, 15)
                    ->by('ip:'.$request->ip())
                    ->response($throttledResponse),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) use ($throttledResponse): array {
            $challengeId = $request->input('challenge_id');
            $challengeKey = is_int($challengeId) || is_string($challengeId)
                ? (string) $challengeId
                : 'invalid';

            return [
                Limit::perMinutes(5, 10)->by(
                    'challenge:'.$challengeKey.'|'.$request->ip(),
                )->response($throttledResponse),
                Limit::perMinutes(5, 30)->by(
                    'ip:'.$request->ip(),
                )->response($throttledResponse),
            ];
        });

        RateLimiter::for('login', function (Request $request) use ($throttledResponse): Limit {
            $email = $request->input('email');
            $email = is_string($email) ? strtolower($email) : 'invalid';

            return Limit::perMinute(5)->by(
                $email.'|'.$request->ip(),
            )->response($throttledResponse);
        });

    }
}
