<?php

namespace App\Support;

use App\Contracts\ShippingRateProvider;

final class CheckoutShipping
{
    public static function quote(ShippingRateProvider $provider, array $address, array $items): array
    {
        if (config('checkout.free_shipping')) {
            return ['fee' => 0, 'service' => 'FREE', 'eta_days' => null];
        }

        return ManualCheckout::enabled()
            ? ManualCheckout::shippingQuote()
            : $provider->quote($address, $items);
    }
}
