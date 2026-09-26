<?php

namespace App\Contracts;

interface WarehouseStockProvider
{
    /**
     * @param  array<int, array{sku: string, quantity: int}>  $items
     * @return array{available: bool, items: array<int, array{sku: string, requested: int, available: int|null}>}
     */
    public function checkAvailability(array $items): array;

    /**
     * @param  array<int, array{sku: string, quantity: int}>  $items
     */
    public function reserve(array $items, string $reference): string;

    public function confirm(string $reservationId): void;

    public function release(string $reservationId): void;
}
