<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\V1\CreateSelfServiceStoreRequest;
use App\Http\Requests\Api\V1\RequestCustomerOtpRequest;
use App\Http\Requests\Web\VerifySellerOtpRequest;
use App\Models\MarketplaceSetting;
use App\Models\Store;
use App\Notifications\VerifyStoreAdminEmailNotification;
use App\Services\Auth\OtpService;
use App\Services\Marketplace\StoreRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class SellerRegistrationController extends Controller
{
    public function login(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('seller.register');
        }

        return view('seller.login');
    }

    public function requestOtp(RequestCustomerOtpRequest $request, OtpService $otpService): RedirectResponse
    {
        $result = $otpService->issue(
            phone: $request->string('phone')->toString(),
            purpose: 'customer_login',
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $request->session()->put([
            'seller_otp.phone' => $request->string('phone')->toString(),
            'seller_otp.challenge_id' => $result['challenge']->id,
        ]);

        return back()->with('otp_sent', 'OTP sudah dikirim. Silakan periksa WhatsApp Anda.');
    }

    public function verifyOtp(VerifySellerOtpRequest $request, OtpService $otpService): RedirectResponse
    {
        $phone = (string) $request->session()->get('seller_otp.phone');
        $challengeId = (int) $request->session()->get('seller_otp.challenge_id');

        if ($phone === '' || $challengeId < 1) {
            return redirect()->route('seller.login')->withErrors([
                'otp' => 'Sesi OTP tidak ditemukan. Silakan minta OTP baru.',
            ]);
        }

        $result = $otpService->verify(
            phone: $phone,
            challengeId: $challengeId,
            otp: $request->string('otp')->toString(),
            deviceName: 'seller-web',
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        Auth::login($result['user']);
        $request->session()->regenerate();
        $request->session()->forget('seller_otp');

        return redirect()->route('seller.register');
    }

    public function create(): View|RedirectResponse
    {
        if (! MarketplaceSetting::enabled('self_service_store_registration_enabled')) {
            return view('seller.registration-closed');
        }

        if (! Auth::check()) {
            return redirect()->route('seller.login');
        }

        $user = Auth::user();
        $store = $user->store;

        if ($store || $user->managedStores()->exists()) {
            return redirect()->route('seller.register.status');
        }

        return view('seller.register', ['user' => $user]);
    }

    public function store(
        CreateSelfServiceStoreRequest $request,
        StoreRegistrationService $registrationService,
    ): RedirectResponse {
        if (! MarketplaceSetting::enabled('self_service_store_registration_enabled')) {
            return redirect()->route('seller.register')->withErrors([
                'registration' => 'Pendaftaran seller sedang ditutup.',
            ]);
        }

        $user = $request->user();
        $store = $registrationService->register($user, $request->validated());

        $tokenUrl = URL::temporarySignedRoute(
            'store-onboarding.verify-email',
            now()->addDay(),
            ['user' => $user->id, 'hash' => sha1((string) $user->email)],
        );

        Notification::route('mail', $user->email)
            ->notify(new VerifyStoreAdminEmailNotification($tokenUrl));

        $request->session()->put('seller_registration.store_id', $store->id);

        return redirect()->route('seller.register.status')
            ->with('success', 'Pendaftaran toko berhasil dikirim dan menunggu persetujuan.');
    }

    public function status(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('seller.login');
        }

        $store = Auth::user()->store;

        if (! $store instanceof Store) {
            return redirect()->route('seller.register');
        }

        return view('seller.status', ['store' => $store]);
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('seller.login');
    }
}
