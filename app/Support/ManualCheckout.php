<?php

namespace App\Support;

final class ManualCheckout
{
    public static function enabled(): bool
    {
        return config('checkout.mode') === 'manual';
    }

    public static function shippingQuote(): array
    {
        return [
            'fee' => max(0, (int) config('checkout.manual.shipping_fee', 0)),
            'service' => 'MANUAL',
            'eta_days' => null,
        ];
    }
}
