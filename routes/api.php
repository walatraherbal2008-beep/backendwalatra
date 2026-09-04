<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
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
