<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseDirectoryProvider;
use App\Models\Product;
use App\Models\StoreProductListing;
use App\Models\StoreProductListingWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreListingWarehouseAssignmentService
{
    public function __construct(
        private readonly WarehouseDirectoryProvider $warehouses,
    ) {}

    /** @return array<string, string> */
    public function availableOptions(int $productId): array
    {
        $product = Product::query()
            ->with(['variants' => fn ($query) => $query
                ->where('status', 'active')
                ->with('warehouseAssignments')])
            ->find($productId);

        if (! $product || $product->variants->isEmpty()) {
            return [];
        }

        $eligibleCodes = $product->variants
            ->map(fn ($variant) => $variant->warehouseAssignments
                ->pluck('warehouse_code')
                ->unique()
                ->values()
                ->all())
            ->reduce(
                fn (?array $carry, array $codes): array => $carry === null
                    ? $codes
                    : array_values(array_intersect($carry, $codes)),
                null,
            ) ?? [];

        if ($eligibleCodes === []) {
            return [];
        }

        return collect($this->warehouses->list())
            ->filter(fn (array $warehouse): bool => in_array($warehouse['code'], $eligibleCodes, true))
            ->mapWithKeys(fn (array $warehouse): array => [
                $warehouse['code'] => $warehouse['realname'].' ('.$warehouse['codename'].')',
            ])
            ->all();
    }

    /** @param list<string> $warehouseCodes */
    public function sync(StoreProductListing $listing, array $warehouseCodes): void
    {
        $warehouseCodes = array_values(array_unique(array_filter(
            array_map(fn (mixed $code): string => trim((string) $code), $warehouseCodes),
            fn (string $code): bool => $code !== '',
        )));
        if ($warehouseCodes === []) {
            $listing->warehouseAssignments()->delete();

            return;
        }

        $availableCodes = array_keys($this->availableOptions($listing->product_id));
        $invalidCodes = array_values(array_diff($warehouseCodes, $availableCodes));

        if ($invalidCodes !== []) {
            throw ValidationException::withMessages([
                'warehouse_codes' => 'Gudang tidak tersedia untuk seluruh varian produk: '.implode(', ', $invalidCodes),
            ]);
        }

        DB::transaction(function () use ($listing, $warehouseCodes): void {
            $listing->warehouseAssignments()
                ->whereNotIn('warehouse_code', $warehouseCodes ?: ['__none__'])
                ->delete();

            foreach ($warehouseCodes as $warehouseCode) {
                $listing->warehouseAssignments()->firstOrCreate([
                    'warehouse_code' => $warehouseCode,
                ]);
            }
        });
    }
}
