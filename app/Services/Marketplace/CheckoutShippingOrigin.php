<?php

namespace App\Services\Marketplace;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseUnavailableException;
use App\Models\ProductVariant;
use App\Models\WarehouseProductVariant;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use App\Services\Warehouse\NearestWarehouseSelector;
use App\Support\CheckoutStock;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class CheckoutShippingOrigin
{
    public function __construct(
        private readonly WarehouseDirectoryProvider $directory,
        private readonly ExternalWarehouseDirectoryClient $client,
    ) {}

    public function resolve(Collection $items, bool $lock = false, ?array $address = null): array
    {
        $nearest = CheckoutStock::nearest();
        $stockModeCompatible = CheckoutStock::unlimited() || CheckoutStock::checkOnly();
        if (($nearest && (! $stockModeCompatible || ! config('checkout.free_shipping')))
            || (! $nearest && CheckoutStock::checkOnly())) {
            throw new WarehouseUnavailableException('Pemilihan gudang terdekat memerlukan mode stok unlimited atau check_only dan gratis ongkir.');
        }
        $variantIds = $items->flatMap(fn ($item) => $item->isBundle()
            ? $item->bundleListing->bundle->items->pluck('product_variant_id')
            : [$item->product_variant_id])->unique()->sort()->values();
        $variants = ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');
        $assignments = WarehouseProductVariant::query()->whereIn('product_variant_id', $variantIds)
            ->orderBy('product_variant_id')->orderBy('warehouse_code')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->groupBy('product_variant_id');
        $common = null;
        $missing = [];

        foreach ($variantIds as $id) {
            $codes = $assignments->get($id, collect())->pluck('warehouse_code')->unique()->values()->all();
            if ($codes === []) {
                $missing[] = ['variant_id' => $id, 'sku' => $variants->get($id)?->sku];
            }
            $common = $common === null ? $codes : array_values(array_intersect($common, $codes));
        }

        if ($missing !== []) {
            $this->conflict('WAREHOUSE_NOT_ASSIGNED', 'Titik pengiriman sebagian barang belum diatur. Silakan hubungi admin.', ['variants' => $missing]);
        }
        if (empty($common)) {
            $this->conflict('WAREHOUSE_MISMATCH', 'Barang yang dipilih belum memiliki satu titik pengiriman yang sama. Silakan ubah pilihan barang.');
        }

        if (CheckoutStock::nearest()) {
            return app(NearestWarehouseSelector::class)->select(
                $common,
                CheckoutStock::checkOnly() ? app(CheckoutReview::class)->productRequirements($items) : [],
                $address,
                $lock,
            );
        }

        $preferred = trim((string) config('checkout.preferred_warehouse_code', ''));
        if ($preferred !== '' && ! in_array($preferred, $common, true)) {
            $this->conflict('WAREHOUSE_NOT_AVAILABLE', 'Barang yang dipilih belum tersedia pada titik pengiriman yang ditetapkan.');
        }
        if ($preferred === '' && count($common) > 1) {
            $this->conflict('WAREHOUSE_SELECTION_REQUIRED', 'Terdapat beberapa titik pengiriman. Admin perlu menetapkan titik pengiriman sebelum checkout.');
        }
        $code = $preferred !== '' ? $preferred : $common[0];
        $warehouse = collect($this->directory->list())->first(fn (array $row): bool => $row['code'] === $code);
        if ($warehouse === null) {
            $this->conflict('WAREHOUSE_NOT_AVAILABLE', 'Titik pengiriman barang tidak ditemukan. Silakan hubungi admin.');
        }
        $detail = $this->client->detail($code);

        return [
            'warehouse_code' => $code,
            'warehouse_name' => $warehouse['realname'],
            'address' => $detail['fulladdr'] ?: null,
            'postal_code' => $detail['postal_code'] ?: null,
            'coordinates' => $detail['coordinates'] ?: null,
        ];
    }

    private function conflict(string $code, string $message, ?array $data = null): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false, 'message' => $message, 'code' => $code, 'errors' => null, 'data' => $data,
        ], 409));
    }
}
