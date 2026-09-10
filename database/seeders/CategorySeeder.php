<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['name' => 'Pencernaan', 'slug' => 'pencernaan', 'sort_order' => 1],
            ['name' => 'Pernapasan', 'slug' => 'pernapasan', 'sort_order' => 2],
            ['name' => 'Daya Tahan Tubuh', 'slug' => 'daya-tahan-tubuh', 'sort_order' => 3],
            ['name' => 'Sendi dan Otot', 'slug' => 'sendi-dan-otot', 'sort_order' => 4],
            ['name' => 'Kesehatan Wanita', 'slug' => 'kesehatan-wanita', 'sort_order' => 5],
            ['name' => 'Nutrisi dan Kebugaran', 'slug' => 'nutrisi-dan-kebugaran', 'sort_order' => 6],
        ])->mapWithKeys(function (array $data): array {
            $category = Category::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'status' => 'active',
                    'sort_order' => $data['sort_order'],
                ],
            );

            return [$data['slug'] => $category];
        });

        $productCategories = [
            'PROD-WAL-LAMBUNGKU' => ['pencernaan'],
            'PROD-WAL-PROPOLIS-SM' => ['pernapasan', 'daya-tahan-tubuh'],
            'PROD-WAL-QNC-JELLY-GAMAT' => ['daya-tahan-tubuh', 'sendi-dan-otot'],
            'PROD-WAL-BIO-SQUALENE' => ['daya-tahan-tubuh', 'nutrisi-dan-kebugaran'],
            'PROD-WAL-GARLIC' => ['daya-tahan-tubuh'],
            'PROD-WAL-SENDIFIT' => ['sendi-dan-otot'],
            'PROD-WAL-OIL-FIT' => ['sendi-dan-otot'],
            'PROD-WAL-SEHAT-WANITA' => ['kesehatan-wanita'],
            'PROD-WAL-ETAKU' => ['nutrisi-dan-kebugaran'],
        ];

        foreach ($productCategories as $productCode => $categorySlugs) {
            $product = Product::query()->where('product_code', $productCode)->first();

            if (! $product) {
                continue;
            }

            $product->categories()->sync(
                collect($categorySlugs)
                    ->map(fn (string $slug): int => $categories[$slug]->id)
                    ->all(),
            );
        }

        $this->command?->info('Kategori dummy dan relasi produk berhasil disinkronkan.');
    }
}
