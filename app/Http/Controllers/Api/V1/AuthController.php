<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\UpdateCustomerProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Support\ApiResponse;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->where('user_type', 'customer')
            ->where('status', 'active')
            ->first();

        if (
            ! $user
            || $user->password === null
            || ! Hash::check($request->string('password')->toString(), $user->password)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password tidak valid.',
                'code' => 'INVALID_CREDENTIALS',
                'errors' => null,
            ], 401);
        }

        $token = $user->createToken(
            $request->string('device_name')->toString(),
            ['customer-api'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'code' => 'AUTHENTICATED',
            'data' => [
                'user' => $user->only(['id', 'name', 'email']),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ])->header('Cache-Control', 'no-store, private');
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
