<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Contracts\WarehouseStockProvider;
use App\Contracts\PaymentGateway;
use App\Contracts\ShippingRateProvider;
use App\Contracts\ShippingLocationProvider;
use App\Contracts\WarehouseDirectoryProvider;
use App\Services\Payments\MidtransPaymentGateway;
use App\Services\Payments\UnavailablePaymentGateway;
use App\Services\Shipping\UnavailableShippingRateProvider;
use App\Services\Shipping\ShippingApiRateProvider;
use App\Services\Shipping\EverproLocationProvider;
use App\Services\Warehouse\UnavailableWarehouseStockProvider;
use App\Services\Warehouse\WarehouseApiStockProvider;
use App\Services\Warehouse\ExternalWarehouseDirectoryProvider;
use App\Observers\ProductObserver;
use App\Observers\ProductVariantObserver;
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
    public function register(): void
    {
        $this->app->singleton(ShippingLocationProvider::class, function ($app): ShippingLocationProvider {
            return $app->make(EverproLocationProvider::class);
        });

        $this->app->singleton(WarehouseDirectoryProvider::class, function ($app): WarehouseDirectoryProvider {
            return $app->make(ExternalWarehouseDirectoryProvider::class);
        });

        $this->app->singleton(
            WarehouseStockProvider::class,
            function ($app): WarehouseStockProvider {
                return config('services.warehouse.enabled')
                    && filled(config('services.warehouse.base_url'))
                    && filled(config('services.warehouse.token'))
                    ? $app->make(WarehouseApiStockProvider::class)
                    : $app->make(UnavailableWarehouseStockProvider::class);
            },
        );
        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return filled(config('services.midtrans.server_key'))
                ? $app->make(MidtransPaymentGateway::class)
                : $app->make(UnavailablePaymentGateway::class);
        });
        $this->app->singleton(ShippingRateProvider::class, function ($app): ShippingRateProvider {
            return config('services.shipping.enabled')
                && filled(config('services.shipping.base_url'))
                && filled(config('services.shipping.token'))
                ? $app->make(ShippingApiRateProvider::class)
                : $app->make(UnavailableShippingRateProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        ProductVariant::observe(ProductVariantObserver::class);

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

        RateLimiter::for('catalog', function (Request $request) use ($throttledResponse): array {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(120)
                    ->by('catalog:'.$key)
                    ->response($throttledResponse),
            ];
        });

    }
}
