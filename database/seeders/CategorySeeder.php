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
            ['name' => 'Pernapasan dan Tenggorokan', 'slug' => 'pernapasan-dan-tenggorokan', 'sort_order' => 2],
            ['name' => 'Daya Tahan Tubuh', 'slug' => 'daya-tahan-tubuh', 'sort_order' => 3],
            ['name' => 'Sendi dan Otot', 'slug' => 'sendi-dan-otot', 'sort_order' => 4],
            ['name' => 'Kesehatan Wanita', 'slug' => 'kesehatan-wanita', 'sort_order' => 5],
            ['name' => 'Nutrisi dan Kebugaran', 'slug' => 'nutrisi-dan-kebugaran', 'sort_order' => 6],
            ['name' => 'Kesehatan Kulit', 'slug' => 'kesehatan-kulit', 'sort_order' => 7],
            ['name' => 'Kesehatan Mata', 'slug' => 'kesehatan-mata', 'sort_order' => 8],
            ['name' => 'Vitalitas Pria', 'slug' => 'vitalitas-pria', 'sort_order' => 9],
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
            'kapsul-sehat-lambungku' => ['pencernaan'],
            'propolis-sm' => ['pernapasan-dan-tenggorokan', 'daya-tahan-tubuh'],
            'qnc-jelly-gamat' => ['daya-tahan-tubuh', 'sendi-dan-otot'],
            'bio-squalene' => ['daya-tahan-tubuh', 'nutrisi-dan-kebugaran'],
            'garlic' => ['daya-tahan-tubuh'],
            'sendifit' => ['sendi-dan-otot'],
            'kapsul-oil-fit' => ['sendi-dan-otot'],
            'sehat-wanita' => ['kesehatan-wanita'],
            'etaku-goat-milk' => ['nutrisi-dan-kebugaran'],
            'proskin' => ['kesehatan-kulit'],
            'keloreena' => ['vitalitas-pria', 'nutrisi-dan-kebugaran'],
            'hexabumin' => ['nutrisi-dan-kebugaran'],
            'albucare' => ['nutrisi-dan-kebugaran'],
            'kurmaqu-gmj' => ['nutrisi-dan-kebugaran'],
            'radimax' => ['nutrisi-dan-kebugaran'],
            'kapsul-gamat-emas' => ['daya-tahan-tubuh', 'sendi-dan-otot'],
        ];

        foreach ($productCategories as $productSlug => $categorySlugs) {
            $product = Product::query()->where('slug', $productSlug)->first();

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
