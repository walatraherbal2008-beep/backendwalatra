<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ClientIdentity
{
    public static function ip(Request $request): string
    {
        $fallback = (string) $request->ip();
        $secret = (string) config('security.bridge_secret');
        $ip = $request->header('X-Walatra-Client-IP', '');
        $timestamp = $request->header('X-Walatra-Client-Time', '');
        $signature = $request->header('X-Walatra-Client-Signature', '');
        if (strlen($secret) < 32 || ! filter_var($ip, FILTER_VALIDATE_IP)
            || ! preg_match('/^\d{10}$/D', $timestamp) || abs(time() - (int) $timestamp) > 30
            || ! preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            return $fallback;
        }
        $expected = hash_hmac('sha256', implode("\n", [$timestamp, $ip, $request->method(), $request->getPathInfo()]), $secret);

        return hash_equals($expected, $signature) ? $ip : $fallback;
    }
}
