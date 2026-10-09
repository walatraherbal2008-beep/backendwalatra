<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogBundleController;
use App\Http\Controllers\Api\V1\CatalogCategoryController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogProductController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\ShippingLocationController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\StoreOnboardingController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:catalog')->group(function (): void {
        Route::get('/catalog/products', [CatalogProductController::class, 'index']);
        Route::get('/catalog', [CatalogController::class, 'index']);
        Route::get('/catalog/products/{slug}', [CatalogProductController::class, 'show']);
        Route::get('/catalog/categories', [CatalogCategoryController::class, 'index']);
        Route::get('/catalog/categories/{slug}', [CatalogCategoryController::class, 'show']);
        Route::get('/catalog/bundles', [CatalogBundleController::class, 'index']);
        Route::get('/catalog/bundles/{slug}', [CatalogBundleController::class, 'show']);
        Route::get('/shipping/locations/provinces', [ShippingLocationController::class, 'provinces']);
        Route::get('/shipping/locations/cities', [ShippingLocationController::class, 'cities']);
        Route::get('/shipping/locations/districts', [ShippingLocationController::class, 'districts']);
        Route::get('/shipping/locations/sub-districts', [ShippingLocationController::class, 'subDistricts']);
        Route::post('/shipping/locations/resolve', [ShippingLocationController::class, 'resolve']);
        Route::get('/warehouses', [WarehouseController::class, 'index']);
        Route::post('/warehouses/group', [WarehouseController::class, 'group']);
    });

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'abilities:customer-api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::patch('/auth/customer/profile', [AuthController::class, 'updateCustomerProfile'])
            ->middleware(EnsureUserIsActive::class);
        Route::get('/auth/me', [AuthController::class, 'me'])
            ->middleware(EnsureUserIsActive::class);
        Route::middleware(EnsureUserIsActive::class)->group(function (): void {
            Route::get('/cart', [CartController::class, 'show']);
            Route::post('/cart/items', [CartController::class, 'store']);
            Route::patch('/cart/items/{item}', [CartController::class, 'update']);
            Route::delete('/cart/items/{item}', [CartController::class, 'destroy']);
            Route::delete('/cart', [CartController::class, 'clear']);
            Route::apiResource('addresses', AddressController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::post('/checkout/preview', [CheckoutController::class, 'preview']);
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
            Route::post('/orders/{order}/payment', [PaymentController::class, 'create']);
        });
    });

    Route::post('/payments/midtrans/webhook', [PaymentController::class, 'webhook']);
    Route::post(
        '/auth/customer/request-otp',
        [CustomerAuthController::class, 'requestOtp'],
    )->middleware('throttle:otp-request');

    Route::post(
        '/auth/customer/verify-otp',
        [CustomerAuthController::class, 'verifyOtp'],
    )->middleware('throttle:otp-verify');

    Route::get('/store-onboarding/verify-email/{user}/{hash}', [StoreOnboardingController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('store-onboarding.verify-email');
    Route::get('/store/admin-invitations/accept/{token}', [StoreOnboardingController::class, 'showInvitation'])
        ->name('store-admin-invitations.accept');

    Route::middleware(['auth:sanctum', 'abilities:customer-api', EnsureUserIsActive::class])->group(function (): void {
        Route::post('/store-onboarding', [StoreOnboardingController::class, 'registerStore'])
            ->middleware('throttle:store-onboarding');
        Route::post('/store/admin-invitations', [StoreOnboardingController::class, 'inviteAdmin'])
            ->middleware('throttle:store-admin-invitation');
        Route::post('/store/admin-invitations/accept', [StoreOnboardingController::class, 'acceptInvitation'])
            ->middleware('throttle:store-admin-invitation');
    });
});
