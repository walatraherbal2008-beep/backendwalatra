<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Request berhasil.',
        ?array $meta = null,
        ?array $links = null,
        int $status = 200,
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        if ($links !== null) {
            $payload['links'] = $links;
        }

        return response()->json($payload, $status);
    }

    public static function error(
        string $message,
        string $code,
        ?array $errors = null,
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
        ], $status);
    }
}
