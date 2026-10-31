<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SellerRegistrationController;
use App\Http\Controllers\Superadmin\CustomerExportController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/superadmin/customers/export', CustomerExportController::class)
    ->middleware('auth')
    ->name('superadmin.customers.export');

Route::prefix('seller')->name('seller.')->group(function (): void {
    Route::get('/login', [SellerRegistrationController::class, 'login'])->name('login');
    Route::post('/login/request-otp', [SellerRegistrationController::class, 'requestOtp'])
        ->middleware('throttle:otp-request')
        ->name('login.request-otp');
    Route::post('/login/verify-otp', [SellerRegistrationController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verify')
        ->name('login.verify-otp');
    Route::get('/register', [SellerRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [SellerRegistrationController::class, 'store'])
        ->middleware(['auth', 'throttle:store-onboarding'])
        ->name('register.store');
    Route::get('/register/status', [SellerRegistrationController::class, 'status'])->name('register.status');
    Route::post('/logout', [SellerRegistrationController::class, 'logout'])
        ->middleware('auth')
        ->name('logout');
});
