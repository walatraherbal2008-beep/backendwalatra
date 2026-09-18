<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogBundleController;
use App\Http\Controllers\Api\V1\CatalogCategoryController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogProductController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/catalog/products', [CatalogProductController::class, 'index']);
    Route::get('/catalog', [CatalogController::class, 'index']);
    Route::get('/catalog/products/{slug}', [CatalogProductController::class, 'show']);
    Route::get('/catalog/categories', [CatalogCategoryController::class, 'index']);
    Route::get('/catalog/categories/{slug}', [CatalogCategoryController::class, 'show']);
    Route::get('/catalog/bundles', [CatalogBundleController::class, 'index']);
    Route::get('/catalog/bundles/{slug}', [CatalogBundleController::class, 'show']);

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::patch('/auth/customer/profile', [AuthController::class, 'updateCustomerProfile'])
            ->middleware(EnsureUserIsActive::class);
        Route::get('/auth/me', [AuthController::class, 'me'])
            ->middleware(EnsureUserIsActive::class);
    });
    Route::post(
        '/auth/customer/request-otp',
        [CustomerAuthController::class, 'requestOtp'],
    )->middleware('throttle:otp-request');

    Route::post(
        '/auth/customer/verify-otp',
        [CustomerAuthController::class, 'verifyOtp'],
    )->middleware('throttle:otp-verify');
});
