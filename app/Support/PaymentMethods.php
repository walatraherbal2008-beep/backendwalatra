<?php

namespace App\Support;

final class PaymentMethods
{
    public const LABELS = [
        'bca_va' => 'BCA Virtual Account', 'bni_va' => 'BNI Virtual Account', 'bri_va' => 'BRI Virtual Account',
        'mandiri_bill' => 'Mandiri Bill Payment', 'permata_va' => 'Permata Virtual Account', 'qris' => 'QRIS',
    ];

    public static function enabled(): array
    {
        return array_values(array_intersect(array_keys(self::LABELS), config('services.midtrans.methods', [])));
    }

    public static function environment(): string
    {
        return config('services.midtrans.is_production') ? 'production' : 'sandbox';
    }

    public static function ttl(string $method): int
    {
        return max(1, (int) config('services.midtrans.'.($method === 'qris' ? 'qris_expiry_minutes' : 'va_expiry_minutes')));
    }

    public static function type(string $method): string
    {
        return match ($method) {
            'qris' => 'qris', 'mandiri_bill' => 'echannel', default => 'bank_transfer'
        };
    }
}
