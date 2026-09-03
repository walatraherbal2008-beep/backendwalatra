<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {


    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        RateLimiter::for('otp-request',function(Request $request):array{
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));

                return [
                Limit::perMinute(1)->by('phone:'.$phone),
                Limit::perMinute(5)->by('ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });


    }


}
