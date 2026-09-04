<?php

namespace App\Providers;

use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
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

        RateLimiter::for('otp-request', function (Request $request): array {
            $rawPhone = (string) $request->input('phone');

            try {
                $phoneKey = PhoneNumber::normalize($rawPhone);
            } catch (InvalidArgumentException) {
                $phoneKey = 'invalid:'.hash('sha256', $rawPhone);
            }

            return [
                Limit::perMinute(5, 3)->by('phone:'.$phoneKey),
                Limit::perMinute(5, 15)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request): array {
            return [
                Limit::perMinutes(5, 10)->by(
                    'challenge:'.$request->input('challenge_id').'|'.$request->ip(),
                ),
                Limit::perMinutes(5, 30)->by(
                    'ip:'.$request->ip(),
                ),
            ];
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

    }
}
