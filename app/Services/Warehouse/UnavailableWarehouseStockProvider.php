<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseStockProvider;
use App\Exceptions\WarehouseUnavailableException;

class UnavailableWarehouseStockProvider implements WarehouseStockProvider
{
    public function checkAvailability(array $items): array
    {
        return [
            'available' => false,
            'items' => collect($items)->map(fn (array $item): array => [
                'sku' => $item['sku'],
                'requested' => $item['quantity'],
                'available' => null,
            ])->values()->all(),
        ];
    }

    public function reserve(array $items, string $reference): string
    {
        throw new WarehouseUnavailableException('API gudang belum tersedia.');
    }

    public function confirm(string $reservationId): void
    {
        throw new WarehouseUnavailableException('API gudang belum tersedia.');
    }

    public function release(string $reservationId): void
    {
        throw new WarehouseUnavailableException('API gudang belum tersedia.');
    }
}
