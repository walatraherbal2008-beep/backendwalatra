<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Support\WalatraOriginalProductCatalog;
use App\Support\WalatraProductCategoryMap;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WalatraCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            if (app()->environment(['local', 'testing'])) {
                $this->resetLocalCatalog();
            }

            $variants = [];

            foreach ($this->catalog() as $productData) {
                $product = Product::withTrashed()
                    ->where('product_code', $productData['code'])
                    ->first();

                if (! $product) {
                    $slugConflict = Product::withTrashed()
                        ->where('slug', $productData['slug'])
                        ->first();

                    if ($slugConflict) {
                        $slugConflict->forceFill([
                            'slug' => $productData['slug'].'-legacy-'.$slugConflict->id,
                            'status' => 'draft',
                        ])->save();
                        $slugConflict->variants()->update(['status' => 'inactive']);
                        StoreProductListing::query()
                            ->where('product_id', $slugConflict->id)
                            ->update(['status' => 'inactive']);
                    }

                    $product = new Product;
                    $product->forceFill(['product_code' => $productData['code']]);
                }

                if ($product->trashed()) {
                    $product->restore();
                }

                $product->forceFill([
                    'name' => $productData['name'],
                    'slug' => $productData['slug'],
                    'status' => 'draft',
                ])->save();

                foreach ($productData['variants'] as $variantData) {
                    $variant = ProductVariant::query()->updateOrCreate(
                        ['sku' => $variantData['sku']],
                        [
                            'product_id' => $product->id,
                            'name' => $variantData['name'],
                            'price' => $variantData['price'],
                            'weight_grams' => $variantData['weight_grams'],
                            'attributes' => ['shipping_weight_status' => 'unknown'],
                            'status' => 'active',
                        ],
                    );

                    $variants[$variantData['sku']] = $variant;
                }

                $categoryIds = Category::query()
                    ->whereIn('slug', WalatraProductCategoryMap::map()[$productData['slug']] ?? [])
                    ->where('status', 'active')
                    ->pluck('id');

                if ($categoryIds->isEmpty()) {
                    throw new \RuntimeException(
                        "Kategori aktif untuk produk '{$productData['name']}' belum tersedia. Jalankan CategorySeeder terlebih dahulu.",
                    );
                }

                $product->categories()->sync($categoryIds->all());

                $product->forceFill(['status' => 'active'])->save();
            }

            foreach ($this->bundles() as $bundleData) {
                $bundle = ProductBundle::query()->updateOrCreate(
                    ['slug' => $bundleData['slug']],
                    [
                        'name' => $bundleData['name'],
                        'price' => $bundleData['price'],
                        'status' => 'active',
                    ],
                );

                $bundle->items()->withTrashed()->forceDelete();

                foreach ($bundleData['items'] as $sku => $quantity) {
                    $bundle->items()->create([
                        'product_variant_id' => $variants[$sku]->id,
                        'quantity' => $quantity,
                    ]);
                }
            }
        });

        $this->command?->info('Katalog produk Walatra berhasil diganti dengan data baru.');
    }

    private function resetLocalCatalog(): void
    {
        StoreProductListing::withTrashed()->eachById(function (StoreProductListing $listing): void {
            $listing->clearMediaCollection('images');
            $listing->forceDelete();
        });

        StoreBundleListing::withTrashed()->eachById(function (StoreBundleListing $listing): void {
            $listing->clearMediaCollection('images');
            $listing->forceDelete();
        });

        ProductBundleItem::withTrashed()->eachById(
            fn (ProductBundleItem $item): ?bool => $item->forceDelete(),
        );
        ProductBundle::withTrashed()->eachById(
            fn (ProductBundle $bundle): ?bool => $bundle->forceDelete(),
        );
        ProductVariant::withTrashed()->eachById(
            fn (ProductVariant $variant): ?bool => $variant->forceDelete(),
        );
        Product::withTrashed()->eachById(function (Product $product): void {
            $product->clearMediaCollection('images');
            $product->forceDelete();
        });
    }

    /**
     * Data identitas dan harga mengikuti daftar produk asli yang diberikan.
     * Berat, nomor BPOM, dan informasi produk lain tidak ditebak dari daftar ini.
     *
     * @return list<array{code: string, name: string, slug: string, variants: list<array{sku: string, name: string, price: int, weight_grams: int}>}>
     */
    private function catalog(): array
    {
        return array_map(static fn (array $product): array => [
            'code' => $product['code'],
            'name' => $product['name'],
            'slug' => $product['slug'],
            'variants' => [[
                'name' => $product['name'],
                'sku' => $product['sku'],
                'price' => $product['price'],
                'weight_grams' => 0,
            ]],
        ], WalatraOriginalProductCatalog::products());
    }

    /**
     * @return list<array{name: string, slug: string, price: int, items: array<string, int>}>
     */
    private function bundles(): array
    {
        return [
            [
                'name' => 'Paket Herbal Keluarga',
                'slug' => 'paket-herbal-keluarga',
                'price' => 350000,
                'items' => [
                    'SKU-WAL-ORIGINAL-WNT' => 1,
                    'SKU-WAL-ORIGINAL-LMB' => 1,
                    'SKU-WAL-ORIGINAL-PSM' => 1,
                ],
            ],
            [
                'name' => 'Paket Herbal Sendi dan Otot',
                'slug' => 'paket-herbal-sendi-dan-otot',
                'price' => 300000,
                'items' => [
                    'SKU-WAL-ORIGINAL-IFI' => 1,
                    'SKU-WAL-ORIGINAL-OIL' => 1,
                    'SKU-WAL-ORIGINAL-KLRN' => 1,
                ],
            ],
            [
                'name' => 'Paket Herbal Daya Tahan',
                'slug' => 'paket-herbal-daya-tahan',
                'price' => 390000,
                'items' => [
                    'SKU-WAL-ORIGINAL-QNC' => 1,
                    'SKU-WAL-ORIGINAL-SQU' => 1,
                    'SKU-WAL-ORIGINAL-HEX' => 1,
                ],
            ],
        ];
    }
}
