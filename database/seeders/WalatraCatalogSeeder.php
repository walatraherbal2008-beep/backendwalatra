<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WalatraCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $variants = [];

        foreach ($this->catalog() as $productData) {
            $product = Product::query()->updateOrCreate(
                ['product_code' => $productData['code']],
                [
                    'name' => $productData['name'],
                    'slug' => $productData['slug'],
                    'bpom_number' => null,
                    'status' => 'active',
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
        }

        foreach ($this->bundles() as $bundleData) {
            $bundle = ProductBundle::query()->updateOrCreate(
                ['code' => $bundleData['code']],
                [
                    'name' => $bundleData['name'],
                    'slug' => $bundleData['slug'],
                    'price' => $bundleData['price'],
                    'status' => 'active',
                ],
            );

            $bundle->items()->delete();

            foreach ($bundleData['items'] as $sku => $quantity) {
                $bundle->items()->create([
                    'product_variant_id' => $variants[$sku]->id,
                    'quantity' => $quantity,
                ]);
            }
        }
    }

    /**
     * @return list<array{name: string, code: string, slug: string, variants: list<array{sku: string, name: string, price: int, weight_grams: int}>}>
     */
    private function catalog(): array
    {
        return [
            $this->product('Proskin', 'PROD-WAL-PROSKIN', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-PROSKIN-01', 'price' => 119000, 'weight_grams' => 150],
            ]),
            $this->product('Sehat Wanita', 'PROD-WAL-SEHAT-WANITA', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-SEHAT-WANITA-50', 'price' => 121000, 'weight_grams' => 100],
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-SEHAT-WANITA-100', 'price' => 180000, 'weight_grams' => 180],
            ]),
            $this->product('Radimax', 'PROD-WAL-RADIMAX', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-RADIMAX-01', 'price' => 125000, 'weight_grams' => 150],
            ]),
            $this->product('Kapsul Sehat Lambungku', 'PROD-WAL-LAMBUNGKU', [
                ['name' => '15 Kapsul', 'sku' => 'SKU-WAL-LAMBUNGKU-15', 'price' => 75000, 'weight_grams' => 50],
                ['name' => '30 Kapsul', 'sku' => 'SKU-WAL-LAMBUNGKU-30', 'price' => 125000, 'weight_grams' => 80],
                ['name' => '60 Kapsul', 'sku' => 'SKU-WAL-LAMBUNGKU-60', 'price' => 250000, 'weight_grams' => 150],
            ]),
            $this->product('Keloreena', 'PROD-WAL-KELOREENA', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-KELOREENA-50', 'price' => 93000, 'weight_grams' => 100],
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-KELOREENA-100', 'price' => 155000, 'weight_grams' => 180],
            ]),
            $this->product('Kurmaqu GMJ', 'PROD-WAL-KURMAQU', [
                ['name' => '1 Pouch', 'sku' => 'SKU-WAL-KURMAQU-01', 'price' => 95000, 'weight_grams' => 250],
            ]),
            $this->product('SendiFit', 'PROD-WAL-SENDIFIT', [
                ['name' => '50 Kapsul', 'sku' => 'SKU-WAL-SENDIFIT-50', 'price' => 120000, 'weight_grams' => 100],
            ]),
            $this->product('Propolis SM', 'PROD-WAL-PROPOLIS-SM', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-PROPOLIS-SM-01', 'price' => 99000, 'weight_grams' => 120],
            ]),
            $this->product('Bio Squalene', 'PROD-WAL-BIO-SQUALENE', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-BIO-SQUALENE-01', 'price' => 110000, 'weight_grams' => 120],
            ]),
            $this->product('Oil Fit', 'PROD-WAL-OIL-FIT', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-OIL-FIT-01', 'price' => 120000, 'weight_grams' => 100],
            ]),
            $this->product('QNC Jelly Gamat', 'PROD-WAL-QNC-JELLY-GAMAT', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-QNC-JELLY-01', 'price' => 180000, 'weight_grams' => 350],
            ]),
            $this->product('Gamat Emas Kapsul', 'PROD-WAL-GAMAT-EMAS', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-GAMAT-EMAS-01', 'price' => 139000, 'weight_grams' => 150],
            ]),
            $this->product('Albucare', 'PROD-WAL-ALBUCARE', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-ALBUCARE-01', 'price' => 180000, 'weight_grams' => 150],
            ]),
            $this->product('Hexabumin', 'PROD-WAL-HEXABUMIN', [
                ['name' => '1 Botol', 'sku' => 'SKU-WAL-HEXABUMIN-01', 'price' => 87000, 'weight_grams' => 350],
            ]),
            $this->product('Garlic', 'PROD-WAL-GARLIC', [
                ['name' => '100 Kapsul', 'sku' => 'SKU-WAL-GARLIC-100', 'price' => 147000, 'weight_grams' => 180],
            ]),
            $this->product('Etaku Goat Milk', 'PROD-WAL-ETAKU', [
                ['name' => '1 Box', 'sku' => 'SKU-WAL-ETAKU-01', 'price' => 80000, 'weight_grams' => 500],
            ]),
        ];
    }

    /**
     * @return list<array{name: string, code: string, slug: string, price: int, items: array<string, int>}>
     */
    private function bundles(): array
    {
        return [
            [
                'name' => 'Paket Herbal Keluarga',
                'code' => 'BND-WAL-KELUARGA',
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
                'code' => 'BND-WAL-SENDI-OTOT',
                'slug' => 'paket-herbal-sendi-dan-otot',
                'price' => 300000,
                'items' => [
                    'SKU-WAL-SENDIFIT-50' => 1,
                    'SKU-WAL-OIL-FIT-01' => 1,
                    'SKU-WAL-KELOREENA-50' => 1,
                ],
            ],
            [
                'name' => 'Paket Herbal Daya Tahan',
                'code' => 'BND-WAL-DAYA-TAHAN',
                'slug' => 'paket-herbal-daya-tahan',
                'price' => 390000,
                'items' => [
                    'SKU-WAL-QNC-JELLY-01' => 1,
                    'SKU-WAL-BIO-SQUALENE-01' => 1,
                    'SKU-WAL-HEXABUMIN-01' => 1,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{sku: string, name: string, price: int, weight_grams: int}>  $variants
     * @return array{name: string, code: string, slug: string, variants: list<array{sku: string, name: string, price: int, weight_grams: int}>}
     */
    private function product(string $name, string $code, array $variants): array
    {
        return [
            'name' => $name,
            'code' => $code,
            'slug' => Str::slug($name),
            'variants' => $variants,
        ];
    }
}
