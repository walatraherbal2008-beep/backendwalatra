<?php

namespace App\Support;

final class CheckoutSimulation
{
    public const STORE_CODE = 'TOKO-SIMULASI-ORDER';

    public static function enabled(): bool
    {
        return ! ManualCheckout::enabled()
            && app()->environment(['local', 'testing'])
            && config('checkout.simulation.enabled') === true;
    }
}
