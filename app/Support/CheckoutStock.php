<?php

namespace App\Support;

final class CheckoutStock
{
    public static function unlimited(): bool
    {
        return config('checkout.stock_mode') === 'unlimited';
    }

    public static function mode(): string
    {
        return self::unlimited() ? 'unlimited' : 'external';
    }
}
