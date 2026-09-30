<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestCustomerOtpRequest;
use App\Http\Requests\Api\V1\VerifyCustomerOtpRequest;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;

class CustomerAuthController extends Controller
{
    public function requestOtp(
        RequestCustomerOtpRequest $request,
        OtpService $otpService,

    ): JsonResponse {
        $result = $otpService->issue(
            phone: $request->string('phone')->toString(),
            purpose: 'customer_login',
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $response = [
            'success' => true,
            'code' => 'OTP_REQUESTED',
            'message' => in_array($result['challenge']->delivery_status, ['pending', 'unknown'], true)
                ? 'OTP sedang diproses. Silakan periksa WhatsApp Anda.'
                : ($result['challenge']->delivery_status === 'failed'
                    ? 'OTP gagal dikirim. Silakan coba lagi.'
                    : 'Jika nomor dapat menerima WhatsApp, OTP akan dikirim.'),
            'data' => [
                'challenge_id' => $result['challenge']->id,
                'expires_at' => $result['challenge']->expires_at?->toIso8601String(),
                'delivery_status' => $result['challenge']->delivery_status,
            ],
        ];

        if (app()->environment(['local', 'testing']) && config('services.otp.expose_code')) {
            $response['data']['otp'] = $result['plain_code'];
        }

        return response()->json($response)->header('Cache-Control', 'no-store, private');
    }

    public function verifyOtp(
        VerifyCustomerOtpRequest $request,
        OtpService $otpService,
    ): JsonResponse {
        $result = $otpService->verify(
            phone: $request->string('phone')->toString(),
            challengeId: $request->integer('challenge_id'),
            otp: $request->string('otp')->toString(),
            deviceName: $request->string('device_name')->toString(),
        );

        return response()->json([
            'success' => true,
            'code' => 'AUTHENTICATED',
            'message' => 'Login berhasil.',
            'data' => [
                'user' => array_merge($result['user']->only([
                    'id',
                    'name',
                    'phone',
                ]), [
                    'profile_completed_at' => $result['user']->profile_completed_at?->toIso8601String(),
                ]),
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'is_new_user' => $result['is_new_user'],
                'requires_profile_completion' => $result['requires_profile_completion'],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
