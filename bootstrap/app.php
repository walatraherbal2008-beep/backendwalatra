<?php

use App\Exceptions\OtpDeliveryException;
use App\Exceptions\WarehouseUnavailableException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\ShippingUnavailableException;
use App\Exceptions\EverproUnavailableException;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (
            Request $request,
            Throwable $exception
        ): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $exception->errors();

            return response()->json([
                'success' => false,
                'message' => collect($errors)->flatten()->first() ?? 'Data yang dikirim tidak valid.',
                'code' => 'VALIDATION_ERROR',
                'errors' => $errors,
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', status: 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Anda tidak memiliki akses.', 'FORBIDDEN', status: 403);
        });

        $exceptions->render(function (TooManyRequestsHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'Terlalu banyak request. Silakan coba lagi nanti.',
                'TOO_MANY_REQUESTS',
                status: 429,
            );
        });

        $exceptions->render(function (OtpDeliveryException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'Layanan WhatsApp sedang tidak tersedia. Silakan coba lagi.',
                'OTP_UNAVAILABLE',
                ['otp' => ['OTP gagal dikirim.']],
                503,
            );
        });

        $exceptions->render(function (WarehouseUnavailableException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Layanan gudang sedang tidak tersedia. Silakan coba lagi.', 'WAREHOUSE_UNAVAILABLE', status: 503);
        });

        $exceptions->render(function (PaymentGatewayUnavailableException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Layanan pembayaran sedang tidak tersedia. Silakan coba lagi.', 'PAYMENT_UNAVAILABLE', status: 503);
        });

        $exceptions->render(function (ShippingUnavailableException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Layanan ongkir sedang tidak tersedia. Silakan coba lagi.', 'SHIPPING_UNAVAILABLE', status: 503);
        });

        $exceptions->render(function (EverproUnavailableException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Layanan lokasi Everpro sedang tidak tersedia. Silakan coba lagi.', 'EVERPRO_UNAVAILABLE', status: 503);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*') || $exception->getStatusCode() !== 404) {
                return null;
            }

            return ApiResponse::error(
                $exception->getMessage() ?: 'Data tidak ditemukan.',
                'NOT_FOUND',
                status: 404,
            );
        });
    })->create();
