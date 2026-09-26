<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingRateProvider;
use App\Exceptions\ShippingUnavailableException;

class UnavailableShippingRateProvider implements ShippingRateProvider
{
    public function quote(array $address, array $items): array
    {
        throw new ShippingUnavailableException('Layanan ongkir belum dikonfigurasi.');
    }
}
