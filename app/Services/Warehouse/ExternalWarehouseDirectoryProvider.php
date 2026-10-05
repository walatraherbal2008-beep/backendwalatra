<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;

class ExternalWarehouseDirectoryProvider implements WarehouseDirectoryProvider
{
    public function __construct(
        private readonly ExternalWarehouseDirectoryClient $client,
    ) {}

    public function list(): array
    {
        return $this->map($this->client->list());
    }

    public function listByGroup(string $areaGroup): array
    {
        return $this->map($this->client->list($areaGroup));
    }

    /** @param list<array<string, mixed>> $rawWarehouses */
    private function map(array $rawWarehouses): array
    {
        return array_values(array_map(function (mixed $warehouse): array {
            if (! is_array($warehouse)) {
                throw new WarehouseDirectoryUnavailableException('Format item gudang tidak valid.');
            }

            foreach (['code', 'initial', 'codename', 'realname', 'area_group'] as $field) {
                if (! array_key_exists($field, $warehouse) || ! is_string($warehouse[$field])) {
                    throw new WarehouseDirectoryUnavailableException('Field '.$field.' pada data gudang tidak valid.');
                }
            }

            return [
                'code' => $warehouse['code'],
                'initial' => $warehouse['initial'],
                'codename' => $warehouse['codename'],
                'realname' => $warehouse['realname'],
                'area_group' => $warehouse['area_group'],
            ];
        }, $rawWarehouses));
    }
}
