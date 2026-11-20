<?php

namespace App\Console\Commands;

use App\Services\Warehouse\WarehouseProductStockSnapshotSync;
use Illuminate\Console\Command;

class SyncWarehouseProductStocks extends Command
{
    protected $signature = 'warehouses:sync-stock';

    protected $description = 'Periksa dan simpan snapshot stok produk per gudang';

    public function handle(WarehouseProductStockSnapshotSync $sync): int
    {
        if (! config('services.warehouse_remaining_stock.enabled')) {
            $this->error('API sisa stok belum diaktifkan.');

            return self::FAILURE;
        }

        $result = $sync->sync();
        $this->info("{$result['succeeded']} stok berhasil diperbarui; {$result['failed']} gagal dari {$result['checked']} pasangan gudang-produk.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
