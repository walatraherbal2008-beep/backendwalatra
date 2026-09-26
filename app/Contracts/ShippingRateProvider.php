<?php

namespace App\Contracts;

interface ShippingRateProvider
{
    /**
     * @param  array<string, mixed>  $address
     * @param  array<int, array{sku: string, quantity: int}>  $items
     * @return array{fee: float, service: string, eta_days: int|null}
     */
    public function quote(array $address, array $items): array;
}
