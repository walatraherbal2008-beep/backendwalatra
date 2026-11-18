<?php

namespace App\Support;

use RuntimeException;

final class ProductionSecurity
{
    public static function check(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (config('app.debug') || config('services.otp.expose_code') || config('session.secure') !== true
            || strlen((string) config('security.bridge_secret')) < 32) {
            throw new RuntimeException('Konfigurasi production tidak aman: nonaktifkan debug/OTP exposure, aktifkan secure cookie, dan pasang secret bridge minimal 32 karakter.');
        }
    }
}
