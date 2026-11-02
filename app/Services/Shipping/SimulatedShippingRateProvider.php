<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingRateProvider;

class SimulatedShippingRateProvider implements ShippingRateProvider
{
    public function quote(array $address, array $items): array
    {
        return [
            'fee' => max(0, (int) config('checkout.simulation.shipping_fee', 0)),
            'service' => 'SIMULATION',
            'eta_days' => null,
        ];
    }
}
