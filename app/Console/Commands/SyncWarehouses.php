<?php

namespace App\Console\Commands;

use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Services\Warehouse\WarehouseSyncService;
use Illuminate\Console\Command;

class SyncWarehouses extends Command
{
    protected $signature = 'warehouses:sync';

    protected $description = 'Sinkronkan master gudang dan koordinat dari API direktori';

    public function handle(WarehouseSyncService $sync): int
    {
        try {
            $result = $sync->sync();
        } catch (WarehouseDirectoryUnavailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info($result['synced'].' gudang disinkronkan; '.$result['without_coordinates'].' belum memiliki koordinat valid.');

        return self::SUCCESS;
    }
}
