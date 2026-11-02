<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseStockProvider;

class SimulatedWarehouseStockProvider implements WarehouseStockProvider
{
    public function checkAvailability(array $items): array
    {
        return [
            'available' => true,
            'items' => array_map(fn (array $item): array => [
                'sku' => $item['sku'],
                'requested' => $item['quantity'],
                'available' => $item['quantity'],
            ], $items),
        ];
    }

    public function reserve(array $items, string $reference): string
    {
        return 'SIM-STOCK-'.$reference;
    }

    public function confirm(string $reservationId): void {}

    public function release(string $reservationId): void {}
}
