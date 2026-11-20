<?php

namespace App\Services\Warehouse;

use App\Exceptions\WarehouseUnavailableException;
use App\Models\WarehouseProductStockSnapshot;
use Illuminate\Support\Facades\DB;

class WarehouseProductStockSnapshotSync
{
    /** @return array{checked: int, succeeded: int, failed: int} */
    public function sync(?int $warehouseId = null, ?int $productId = null, ?callable $onProgress = null): array
    {
        $pairs = DB::table('warehouse_product_variants as assignments')
            ->join('product_variants as variants', 'variants.id', '=', 'assignments.product_variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->join('warehouses', 'warehouses.code', '=', 'assignments.warehouse_code')
            ->where('variants.status', 'active')
            ->whereNull('variants.deleted_at')
            ->where('products.status', 'active')
            ->whereNull('products.deleted_at')
            ->where('warehouses.is_active', true)
            ->where('warehouses.is_listed', true)
            ->when($warehouseId, fn ($query) => $query->where('warehouses.id', $warehouseId))
            ->when($productId, fn ($query) => $query->where('products.id', $productId))
            ->select(['warehouses.id as warehouse_id', 'warehouses.code as warehouse_code', 'products.id as product_id', 'products.product_code'])
            ->distinct()
            ->orderBy('warehouses.code')
            ->orderBy('products.product_code')
            ->get();

        $succeeded = 0;
        $failed = 0;
        $checked = 0;

        if ($onProgress) {
            $onProgress($pairs->count(), $checked, $succeeded, $failed);
        }

        foreach ($pairs as $pair) {
            $snapshot = WarehouseProductStockSnapshot::query()->firstOrNew([
                'warehouse_id' => $pair->warehouse_id,
                'product_id' => $pair->product_id,
            ]);
            $snapshot->last_attempted_at = now();

            try {
                $reportedQuantity = app(WarehouseRemainingStockClient::class)
                    ->quantity($pair->warehouse_code, $pair->product_code);
                $snapshot->reported_quantity = $reportedQuantity;
                $snapshot->available_quantity = max(0, $reportedQuantity);
                $snapshot->status = 'succeeded';
                $snapshot->checked_at = now();
                $snapshot->last_error = null;
                $succeeded++;
            } catch (WarehouseUnavailableException $exception) {
                $snapshot->status = 'failed';
                $snapshot->last_error = $exception->getMessage();
                $failed++;
            }

            $snapshot->save();
            $checked++;
            if ($onProgress) {
                $onProgress($pairs->count(), $checked, $succeeded, $failed);
            }
        }

        if ($warehouseId === null && $productId === null) {
            $this->deleteUnassignedSnapshots();
        }

        return ['checked' => $checked, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    private function deleteUnassignedSnapshots(): void
    {
        WarehouseProductStockSnapshot::query()
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('warehouse_product_variants as assignments')
                    ->join('product_variants as variants', 'variants.id', '=', 'assignments.product_variant_id')
                    ->join('products', 'products.id', '=', 'variants.product_id')
                    ->join('warehouses', 'warehouses.code', '=', 'assignments.warehouse_code')
                    ->whereColumn('warehouses.id', 'warehouse_product_stock_snapshots.warehouse_id')
                    ->whereColumn('products.id', 'warehouse_product_stock_snapshots.product_id')
                    ->where('variants.status', 'active')
                    ->whereNull('variants.deleted_at')
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at')
                    ->where('warehouses.is_active', true)
                    ->where('warehouses.is_listed', true);
            })
            ->delete();
    }
}
