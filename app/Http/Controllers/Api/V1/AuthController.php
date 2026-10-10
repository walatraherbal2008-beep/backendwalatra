<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateCustomerProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\ApiResponse;

class AuthController extends Controller
{
    /**
     * Customer API authentication is OTP-only.
     *
     * Keep this response temporarily so old clients receive an explicit
     * migration error instead of silently falling back to password login.
     */
    public function passwordLoginDisabled(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Login customer hanya menggunakan OTP nomor telepon.',
            'code' => 'OTP_ONLY_AUTHENTICATION',
            'errors' => [
                'authentication' => ['Gunakan endpoint request-otp dan verify-otp.'],
            ],
        ], 410)->header('Cache-Control', 'no-store, private');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(message: 'Logout berhasil.');
    }

    public function updateCustomerProfile(UpdateCustomerProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill([
            'name' => $request->string('name')->trim()->toString(),
            'profile_completed_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil dilengkapi.',
            'code' => 'PROFILE_UPDATED',
            'data' => [
                'user' => array_merge($user->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                ]), [
                    'profile_completed_at' => $user->profile_completed_at?->toIso8601String(),
                ]),
                'requires_profile_completion' => false,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'User aktif.',
            'code' => 'AUTHENTICATED',
            'data' => [
                'user' => array_merge($request->user()->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                ]), [
                    'profile_completed_at' => $request->user()->profile_completed_at?->toIso8601String(),
                ]),
                'requires_profile_completion' => $request->user()->profile_completed_at === null,
            ],
        ]);
    }
}
