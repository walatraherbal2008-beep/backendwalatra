<?php

namespace App\Services\Warehouse;

use App\Exceptions\WarehouseUnavailableException;
use App\Models\Warehouse;
use App\Support\GeoCoordinates;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class NearestWarehouseSelector
{
    public function __construct(private readonly WarehouseRemainingStockClient $stock) {}

    public function select(array $codes, array $requirements, ?array $address, bool $lock = false): array
    {
        if (! GeoCoordinates::valid($address['latitude'] ?? null, $address['longitude'] ?? null)) {
            throw ValidationException::withMessages([
                'shipping_address.latitude' => 'Lengkapi titik latitude dan longitude alamat pengiriman sebelum checkout.',
            ]);
        }
        $candidates = Warehouse::active()->whereIn('code', $codes)->orderBy('code')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->filter(fn (Warehouse $warehouse): bool => GeoCoordinates::valid($warehouse->latitude, $warehouse->longitude))
            ->map(fn (Warehouse $warehouse): array => [
                'warehouse' => $warehouse,
                'distance' => GeoCoordinates::distanceKm((float) $address['latitude'], (float) $address['longitude'], (float) $warehouse->latitude, (float) $warehouse->longitude),
            ])->sort(function (array $a, array $b): int {
                return ($a['distance'] <=> $b['distance']) ?: strcmp($a['warehouse']->code, $b['warehouse']->code);
            });
        if ($candidates->isEmpty()) {
            $this->conflict('WAREHOUSE_NOT_AVAILABLE', 'Belum ada gudang aktif dengan koordinat valid untuk seluruh barang yang dipilih.');
        }
        $deadline = hrtime(true) / 1e9 + min(15, max(0.01, (float) config('checkout.warehouse_selection_timeout', 15)));
        $shortages = [];
        foreach ($candidates as $candidate) {
            $warehouse = $candidate['warehouse'];
            $items = [];
            $available = true;
            foreach ($requirements as $requirement) {
                $quantity = $this->stock->quantity($warehouse->code, $requirement['product_code'], $deadline);
                $sufficient = $quantity >= $requirement['quantity'];
                $items[] = [
                    'product_code' => $requirement['product_code'], 'requested_quantity' => $requirement['quantity'],
                    'available_quantity' => $quantity, 'sufficient' => $sufficient,
                ];
                if (! $sufficient) {
                    $available = false;
                    break;
                }
            }
            if (hrtime(true) / 1e9 > $deadline) {
                throw new WarehouseUnavailableException('Batas waktu pemilihan gudang telah habis.');
            }
            if ($available) {
                return [
                    'warehouse_code' => $warehouse->code, 'warehouse_name' => $warehouse->realname,
                    'address' => $warehouse->fulladdr, 'postal_code' => $warehouse->postal_code,
                    'coordinates' => $warehouse->coordinates,
                    'latitude' => $warehouse->latitude, 'longitude' => $warehouse->longitude,
                    'distance_km' => round($candidate['distance'], 3), 'selection_method' => 'nearest_available',
                    'stock_check' => [
                        'warehouse_code' => $warehouse->code, 'available' => true, 'reserved' => false,
                        'checked_at' => now()->toIso8601String(), 'items' => $items,
                    ],
                ];
            }
            $shortages[] = ['warehouse_code' => $warehouse->code, 'items' => $items];
        }
        $this->conflict('STOCK_UNAVAILABLE', 'Tidak ada satu gudang dengan stok cukup untuk seluruh barang yang dipilih.', ['warehouses' => $shortages]);
    }

    private function conflict(string $code, string $message, ?array $data = null): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false, 'code' => $code, 'message' => $message, 'errors' => null, 'data' => $data,
        ], 409));
    }
}
