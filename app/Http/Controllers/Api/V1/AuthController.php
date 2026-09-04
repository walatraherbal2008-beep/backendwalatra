<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email')->toString())
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
            ], 401);
        }

        $token = $user->createToken(
            $request->string('device_name'),
            ['customer-api'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'user' => $user->only(['id', 'name', 'email']),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'User aktif.',
            'data' => [
                'user' => $request->user()->only(['id', 'name', 'email']),
            ],
        ]);
    }
}
