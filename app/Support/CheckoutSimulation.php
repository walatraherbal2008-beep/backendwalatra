<?php

namespace App\Support;

final class CheckoutSimulation
{
    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing'])
            && config('checkout.simulation.enabled') === true;
    }
}
