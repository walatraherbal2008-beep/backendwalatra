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
        return self::checkOnly() ? 'check_only' : (self::unlimited() ? 'unlimited' : 'external');
    }

    public static function checkOnly(): bool
    {
        return config('checkout.stock_mode') === 'check_only';
    }

    public static function nearest(): bool
    {
        return config('checkout.warehouse_selection') === 'nearest_available';
    }
}
