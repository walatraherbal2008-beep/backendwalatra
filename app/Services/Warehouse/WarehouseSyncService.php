<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Models\Warehouse;
use App\Support\GeoCoordinates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WarehouseSyncService
{
    public function __construct(
        private readonly WarehouseDirectoryProvider $directory,
        private readonly ExternalWarehouseDirectoryClient $client,
    ) {}

    /** @return array{synced: int, without_coordinates: int} */
    public function sync(): array
    {
        $result = Cache::lock('warehouses:sync', 600)->get(function (): array {
            $warehouses = $this->directory->list();
            if ($warehouses === []) {
                throw new WarehouseDirectoryUnavailableException('Daftar gudang kosong. Data lokal dipertahankan.');
            }
            $codes = [];
            foreach ($warehouses as $row) {
                foreach (['code', 'realname', 'codename', 'initial', 'area_group'] as $field) {
                    if (! is_string($row[$field] ?? null) || strlen($row[$field]) > ($field === 'code' ? 100 : 255)) {
                        throw new WarehouseDirectoryUnavailableException('Format daftar gudang tidak valid.');
                    }
                }
                if (trim($row['code']) === '' || trim($row['realname']) === '' || in_array($row['code'], $codes, true)) {
                    throw new WarehouseDirectoryUnavailableException('Kode atau nama gudang kosong atau berulang.');
                }
                $codes[] = $row['code'];
            }

            $rows = [];
            $withoutCoordinates = 0;
            $syncedAt = now();
            foreach ($warehouses as $row) {
                $detail = $this->client->detail($row['code']);
                $coordinates = GeoCoordinates::parse($detail['coordinates']);
                $withoutCoordinates += $coordinates === null ? 1 : 0;
                $rows[] = [
                    ...array_intersect_key($row, array_flip(['code', 'realname', 'codename', 'initial', 'area_group'])),
                    'fulladdr' => $detail['fulladdr'] ?: null,
                    'postal_code' => $detail['postal_code'] ?: null,
                    'coordinates' => $detail['coordinates'] ?: null,
                    'latitude' => $coordinates['latitude'] ?? null,
                    'longitude' => $coordinates['longitude'] ?? null,
                    'is_listed' => true, 'synced_at' => $syncedAt,
                ];
            }

            // Only publish a complete snapshot; a failed detail request must not erase valid local data.
            DB::transaction(function () use ($rows, $codes): void {
                foreach ($rows as $row) {
                    Warehouse::updateOrCreate(['code' => $row['code']], $row);
                }
                Warehouse::whereNotIn('code', $codes)->update(['is_listed' => false]);
            });

            return ['synced' => count($rows), 'without_coordinates' => $withoutCoordinates];
        });
        if ($result === false) {
            throw new WarehouseDirectoryUnavailableException('Sinkronisasi gudang sedang berlangsung.');
        }

        return $result;
    }
}
