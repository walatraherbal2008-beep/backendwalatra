<?php

namespace App\Services\Warehouse;

use App\Contracts\WarehouseDirectoryProvider;
use App\Models\ProductVariant;
use App\Models\WarehouseProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseProductAssignmentService
{
    public function __construct(
        private readonly WarehouseDirectoryProvider $warehouses,
    ) {}

    public function attach(ProductVariant $variant, string $warehouseCode): WarehouseProductVariant
    {
        $this->ensureWarehouseExists($warehouseCode);

        return $variant->warehouseAssignments()->firstOrCreate([
            'warehouse_code' => $warehouseCode,
        ]);
    }

    /**
     * @param list<string> $warehouseCodes
     * @return list<WarehouseProductVariant>
     */
    public function sync(ProductVariant $variant, array $warehouseCodes): array
    {
        $warehouseCodes = array_values(array_unique(array_filter(
            array_map(fn (mixed $code): string => trim((string) $code), $warehouseCodes),
            fn (string $code): bool => $code !== '',
        )));

        $knownCodes = collect($this->warehouses->list())
            ->pluck('code')
            ->all();
        $unknownCodes = array_values(array_diff($warehouseCodes, $knownCodes));

        if ($unknownCodes !== []) {
            throw ValidationException::withMessages([
                'warehouse_codes' => 'Kode gudang tidak ditemukan: '.implode(', ', $unknownCodes),
            ]);
        }

        return DB::transaction(function () use ($variant, $warehouseCodes): array {
            $variant->warehouseAssignments()
                ->whereNotIn('warehouse_code', $warehouseCodes ?: ['__none__'])
                ->delete();

            return array_map(
                fn (string $code): WarehouseProductVariant => $variant->warehouseAssignments()->firstOrCreate([
                    'warehouse_code' => $code,
                ]),
                $warehouseCodes,
            );
        });
    }

    public function detach(ProductVariant $variant, string $warehouseCode): void
    {
        $variant->warehouseAssignments()
            ->where('warehouse_code', $warehouseCode)
            ->delete();
    }

    private function ensureWarehouseExists(string $warehouseCode): void
    {
        $exists = collect($this->warehouses->list())
            ->contains(fn (array $warehouse): bool => $warehouse['code'] === $warehouseCode);

        if (! $exists) {
            throw ValidationException::withMessages([
                'warehouse_code' => 'Kode gudang tidak ditemukan.',
            ]);
        }
    }
}
