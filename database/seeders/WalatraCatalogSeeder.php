<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $product = Product::query()->updateOrCreate(
                    ['slug' => $productData['slug']],
                    [
                        'name' => $productData['name'],
                        'bpom_number' => $productData['bpom_number'],
                        'status' => 'draft',
                    ],
                );

                foreach ($productData['variants'] as $variantData) {
                    $variant = ProductVariant::query()->updateOrCreate(
                        ['sku' => $variantData['sku']],
                        [
                            'product_id' => $product->id,
                            'name' => $variantData['name'],
                            'price' => $variantData['price'],
                            'weight_grams' => $variantData['weight_grams'],
                            'attributes' => null,
                            'status' => 'active',
                        ],
                    );

                    $variants[$variantData['sku']] = $variant;
                }

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
     * Data harga dan varian diselaraskan dengan katalog Walatra yang tersedia.
     * Nomor BPOM hanya diisi untuk data yang terbaca dari referensi kemasan.
     *
     * @return list<array{name: string, slug: string, bpom_number: ?string, variants: list<array{sku: string, name: string, price: int, weight_grams: int}>}>
     */
    private function catalog(): array
    {
        return [
            $this->product('Proskin', 'proskin', 'TR213310581', [
                ['name' => '60 Kapsul', 'sku' => 'SKU-WAL-PROSKIN-60', 'price' => 119000, 'weight_grams' => 150],
            ]),
            $this->product('Sehat Wanita', 'sehat-wanita', 'TR243016761', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-SEHAT-WANITA-50', 'price' => 121000, 'weight_grams' => 100],
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-SEHAT-WANITA-100', 'price' => 180000, 'weight_grams' => 180],
            ]),
            $this->product('Radimax', 'radimax', 'TR242020021', [
                ['name' => '10 Sachet @ 25 gram', 'sku' => 'SKU-WAL-RADIMAX-10X25', 'price' => 125000, 'weight_grams' => 350],
            ]),
            $this->product('Kapsul Sehat Lambungku', 'kapsul-sehat-lambungku', 'TR233040431', [
                ['name' => '15 Kapsul', 'sku' => 'SKU-WAL-LAMBUNGKU-15', 'price' => 75000, 'weight_grams' => 50],
                ['name' => '30 Kapsul', 'sku' => 'SKU-WAL-LAMBUNGKU-30', 'price' => 125000, 'weight_grams' => 80],
                ['name' => '60 Kapsul', 'sku' => 'SKU-WAL-LAMBUNGKU-60', 'price' => 250000, 'weight_grams' => 150],
            ]),
            $this->product('Keloreena', 'keloreena', 'TR240009491', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-KELOREENA-50', 'price' => 93000, 'weight_grams' => 100],
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-KELOREENA-100', 'price' => 155000, 'weight_grams' => 180],
            ]),
            $this->product('Kurmaqu GMJ', 'kurmaqu-gmj', null, [
                ['name' => '1 Botol 350 gram', 'sku' => 'SKU-WAL-KURMAQU-GMJ-350', 'price' => 95000, 'weight_grams' => 450],
            ]),
            $this->product('Sendifit', 'sendifit', 'TR233084841', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-SENDIFIT-50', 'price' => 120000, 'weight_grams' => 100],
            ]),
            $this->product('Propolis SM', 'propolis-sm', 'TR246023521', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-PROPOLIS-SM-01', 'price' => 99000, 'weight_grams' => 120],
            ]),
            $this->product('Bio Squalene', 'bio-squalene', null, [
                ['name' => '30 Kapsul', 'sku' => 'SKU-WAL-BIO-SQUALENE-30', 'price' => 110000, 'weight_grams' => 120],
            ]),
            $this->product('Kapsul Oil Fit', 'kapsul-oil-fit', 'TR233086041', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-OIL-FIT-50', 'price' => 120000, 'weight_grams' => 100],
            ]),
            $this->product('QNC Jelly Gamat', 'qnc-jelly-gamat', 'TR236052391', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-QNC-JELLY-GAMAT-01', 'price' => 180000, 'weight_grams' => 350],
            ]),
            $this->product('Kapsul Gamat Emas', 'kapsul-gamat-emas', 'TR233061561', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-GAMAT-EMAS-50', 'price' => 139000, 'weight_grams' => 150],
            ]),
            $this->product('Albucare', 'albucare', 'TR233063621', [
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-ALBUCARE-100', 'price' => 180000, 'weight_grams' => 150],
            ]),
            $this->product('Hexabumin', 'hexabumin', null, [
                ['name' => '130 ml', 'sku' => 'SKU-WAL-HEXABUMIN-130ML', 'price' => 87000, 'weight_grams' => 350],
            ]),
            $this->product('Garlic', 'garlic', 'TR233069131', [
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-GARLIC-100', 'price' => 147000, 'weight_grams' => 180],
            ]),
            $this->product('Etaku Goat Milk', 'etaku-goat-milk', 'TR242016791', [
                ['name' => '1 Box 200 gram', 'sku' => 'SKU-WAL-ETAKU-200G', 'price' => 80000, 'weight_grams' => 500],
            ]),
        ];
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
                    'SKU-WAL-SEHAT-WANITA-50' => 1,
                    'SKU-WAL-LAMBUNGKU-30' => 1,
                    'SKU-WAL-PROPOLIS-SM-01' => 1,
                ],
            ],
            [
                'name' => 'Paket Herbal Sendi dan Otot',
                'slug' => 'paket-herbal-sendi-dan-otot',
                'price' => 300000,
                'items' => [
                    'SKU-WAL-SENDIFIT-50' => 1,
                    'SKU-WAL-OIL-FIT-50' => 1,
                    'SKU-WAL-KELOREENA-50' => 1,
                ],
            ],
            [
                'name' => 'Paket Herbal Daya Tahan',
                'slug' => 'paket-herbal-daya-tahan',
                'price' => 390000,
                'items' => [
                    'SKU-WAL-QNC-JELLY-GAMAT-01' => 1,
                    'SKU-WAL-BIO-SQUALENE-30' => 1,
                    'SKU-WAL-HEXABUMIN-130ML' => 1,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{sku: string, name: string, price: int, weight_grams: int}>  $variants
     * @return array{name: string, slug: string, bpom_number: ?string, variants: list<array{sku: string, name: string, price: int, weight_grams: int}>}
     */
    private function product(string $name, string $slug, ?string $bpomNumber, array $variants): array
    {
        return [
            'name' => $name,
            'slug' => Str::slug($slug),
            'bpom_number' => $bpomNumber,
            'variants' => $variants,
        ];
    }
}
