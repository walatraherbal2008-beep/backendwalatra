<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Services\Marketplace\StoreOwnerAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WalatraPublishedCatalogSeeder extends Seeder
{
    private const CATALOG_SOURCE = 'https://walatraherbal.com/assets/pdf/katalog-produk.pdf';

    private const STORE_EMAIL = 'toko1@mail.com';

    private const STORE_PASSWORD = 'admintoko';

    public function run(): void
    {
        $this->call(CategorySeeder::class);

        DB::transaction(function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Role::findOrCreate('store_admin', 'web');

            $owner = $this->storeOwner();
            $store = $this->store($owner);

            foreach ($this->catalog() as $sortOrder => $productData) {
                $product = $this->product($productData);

                $this->publishListing(
                    store: $store,
                    product: $product,
                    sortOrder: $sortOrder + 1,
                );
            }
        });

        $this->command?->newLine();
        $this->command?->info('Katalog PDF Walatra berhasil diimpor dan dipublikasikan.');
        $this->command?->line('Toko: Toko Herbal Abadi');
        $this->command?->line('Email admin toko: '.self::STORE_EMAIL);
        $this->command?->warn('Password demo: '.self::STORE_PASSWORD);
        $this->command?->warn('Ganti password akun demo sebelum digunakan di luar pengujian terbatas.');
    }

    private function storeOwner(): User
    {
        $owner = User::query()->where('email', self::STORE_EMAIL)->first();

        if ($owner && $owner->user_type !== 'customer') {
            throw new RuntimeException(
                'Email '.self::STORE_EMAIL.' sudah digunakan oleh user non-customer.',
            );
        }

        $owner ??= new User;

        $owner->forceFill([
            'name' => 'Admin Toko Herbal Abadi',
            'email' => self::STORE_EMAIL,
            'email_verified_at' => now(),
            'password' => Hash::make(self::STORE_PASSWORD),
            'user_type' => 'customer',
            'status' => 'active',
            'profile_completed_at' => now(),
        ])->save();

        $owner->assignRole('store_admin');

        return $owner;
    }

    private function store(User $owner): Store
    {
        $store = Store::query()->where('slug', 'toko-herbal-abadi')->first();

        if (! $store) {
            $store = new Store;
            $store->forceFill([
                'slug' => 'toko-herbal-abadi',
                'code' => 'TOKO-HERBAL-ABADI',
            ]);
        }

        $store->fill([
            'name' => 'Toko Herbal Abadi',
            'description' => 'Toko demo untuk pengujian katalog publik Walatra Herbal.',
            'contact_email' => self::STORE_EMAIL,
            'status' => 'active',
            'email_verified_at' => $store->email_verified_at ?? now(),
            'approved_at' => $store->approved_at ?? now(),
            'opened_at' => $store->opened_at ?? now(),
        ])->save();

        app(StoreOwnerAssignmentService::class)->assign(
            store: $store,
            owner: $owner,
            reason: 'Seeder katalog publik Walatra',
        );

        return $store->fresh();
    }

    /**
     * @param  array{
     *     name: string,
     *     slug: string,
     *     code: string,
     *     bpom_number: string,
     *     categories: list<string>,
     *     variants: list<array{name: string, sku: string, price: int, weight_grams: int, catalog_content: string}>
     * }  $data
     */
    private function product(array $data): Product
    {
        $product = Product::withTrashed()
            ->where('slug', $data['slug'])
            ->orWhere('product_code', $data['code'])
            ->first();

        if (! $product) {
            $product = new Product;
            $product->forceFill(['product_code' => $data['code']]);
        } elseif ($product->trashed()) {
            $product->restore();
        }

        if ($product->exists && $product->product_code !== $data['code']) {
            $this->command?->warn(
                "Produk {$data['name']} sudah memiliki kode {$product->product_code}; kode tersebut dipertahankan.",
            );
        }

        $product->forceFill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'bpom_number' => $data['bpom_number'],
            'status' => 'draft',
        ])->save();

        foreach ($data['variants'] as $variantData) {
            $this->variant($product, $variantData, count($data['variants']) === 1);
        }

        $categoryIds = Category::query()
            ->whereIn('slug', $data['categories'])
            ->where('status', 'active')
            ->pluck('id');

        if ($categoryIds->count() !== count(array_unique($data['categories']))) {
            throw new RuntimeException(
                "Kategori aktif untuk produk {$data['name']} belum lengkap.",
            );
        }

        $product->categories()->sync($categoryIds->all());
        $product->forceFill(['status' => 'active'])->save();

        return $product->fresh(['variants', 'categories']);
    }

    /**
     * @param  array{name: string, sku: string, price: int, weight_grams: int, catalog_content: string}  $data
     */
    private function variant(Product $product, array $data, bool $isOnlyVariant): ProductVariant
    {
        $variant = ProductVariant::withTrashed()
            ->where('sku', $data['sku'])
            ->first();

        $variant ??= ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->whereRaw('LOWER(name) = LOWER(?)', [$data['name']])
            ->first();

        if (! $variant && $isOnlyVariant) {
            $existingVariants = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->get();

            if ($existingVariants->count() === 1) {
                $variant = $existingVariants->first();
            }
        }

        if (! $variant) {
            $variant = new ProductVariant;
            $variant->forceFill([
                'product_id' => $product->id,
                'sku' => $data['sku'],
            ]);
        } elseif ($variant->trashed()) {
            $variant->restore();
        }

        if ((int) $variant->product_id !== $product->id) {
            throw new RuntimeException(
                "SKU {$data['sku']} sudah digunakan oleh produk lain.",
            );
        }

        $variant->forceFill([
            'name' => $data['name'],
            'price' => $data['price'],
            'weight_grams' => $data['weight_grams'],
            'attributes' => [
                'catalog_content' => $data['catalog_content'],
                'catalog_source' => self::CATALOG_SOURCE,
                'shipping_weight_estimated' => true,
            ],
            'status' => 'active',
        ])->save();

        return $variant;
    }

    private function publishListing(Store $store, Product $product, int $sortOrder): void
    {
        $listing = StoreProductListing::withTrashed()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->first();

        if (! $listing) {
            $listing = new StoreProductListing;
            $listing->forceFill([
                'store_id' => $store->id,
                'product_id' => $product->id,
            ]);
        } elseif ($listing->trashed()) {
            $listing->restore();
        }

        $listing->forceFill([
            'description' => "{$product->name} tersedia di Toko Herbal Abadi. Data produk mengacu pada Katalog Produk Walatra Herbal.",
            'status' => 'active',
            'sort_order' => $sortOrder,
            'published_at' => $listing->published_at ?? now(),
        ])->save();
    }

    /**
     * Harga, kode, isi, dan legalitas disalin dari Katalog Produk Walatra
     * (versi PDF dengan metadata modifikasi 15 Februari 2025).
     *
     * Berat pengiriman tidak tersedia di PDF. Nilai weight_grams di bawah
     * adalah estimasi operasional awal dan ditandai pada attributes varian.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     code: string,
     *     bpom_number: string,
     *     categories: list<string>,
     *     variants: list<array{name: string, sku: string, price: int, weight_grams: int, catalog_content: string}>
     * }>
     */
    private function catalog(): array
    {
        return [
            $this->catalogProduct('Albucare', 'albucare', 'ALB', 'TR233063621', ['nutrisi-dan-kebugaran', 'daya-tahan-tubuh'], [
                $this->catalogVariant('100 Kapsul', 'SKU-WAL-ALB-100', 180000, 180, '100 Kapsul'),
            ]),
            $this->catalogProduct('Bio Squalene', 'bio-squalene', 'SQU', 'SD212334131', ['nutrisi-dan-kebugaran', 'daya-tahan-tubuh'], [
                $this->catalogVariant('30 Softgel', 'SKU-WAL-SQU-30', 110000, 120, '30 Softgel'),
            ]),
            $this->catalogProduct('Garlic', 'garlic', 'GRC', 'TR193325651', ['daya-tahan-tubuh'], [
                $this->catalogVariant('100 Kapsul', 'SKU-WAL-GRC-100', 147000, 180, '100 Kapsul'),
            ]),
            $this->catalogProduct('Sendifit', 'sendifit', 'IFI', 'TR233084841', ['sendi-dan-otot'], [
                $this->catalogVariant('50 Kapsul', 'SKU-WAL-IFI-50', 117000, 100, '50 Kapsul'),
            ]),
            $this->catalogProduct('Kapsul Gamat Emas', 'kapsul-gamat-emas', 'KGE', 'TR233061561', ['pencernaan', 'daya-tahan-tubuh'], [
                $this->catalogVariant('50 Kapsul', 'SKU-WAL-KGE-50', 135000, 150, '50 Kapsul'),
            ]),
            $this->catalogProduct('Limatta Sehat Mata', 'limatta-sehat-mata', 'LMT', 'TR193325531', ['kesehatan-mata'], [
                $this->catalogVariant('30 Softgel', 'SKU-WAL-LMT-30', 150000, 120, '30 Softgel'),
            ]),
            $this->catalogProduct('Oil Fit', 'kapsul-oil-fit', 'OIL', 'TR233086041', ['nutrisi-dan-kebugaran'], [
                $this->catalogVariant('50 Kapsul', 'SKU-WAL-OIL-50', 99500, 100, '50 Kapsul'),
            ]),
            $this->catalogProduct('Proskin', 'proskin', 'SKIN', 'TR213310581', ['kesehatan-kulit'], [
                $this->catalogVariant('60 Kapsul', 'SKU-WAL-SKIN-60', 115000, 150, '60 Kapsul'),
            ]),
            $this->catalogProduct('Keloreena', 'keloreena', 'KLR', 'TR193332021', ['nutrisi-dan-kebugaran', 'kesehatan-mata'], [
                $this->catalogVariant('50 Kapsul', 'SKU-WAL-KLRN-50', 87000, 100, '50 Kapsul'),
                $this->catalogVariant('100 Kapsul', 'SKU-WAL-KLR-100', 155000, 180, '100 Kapsul'),
            ]),
            $this->catalogProduct('Sehat Wanita', 'sehat-wanita', 'WNT', 'TR173307361', ['kesehatan-wanita'], [
                $this->catalogVariant('50 Kapsul', 'SKU-WAL-WNT-50', 117000, 100, '50 Kapsul'),
                $this->catalogVariant('100 Kapsul', 'SKU-WAL-WBW-100', 199000, 180, '100 Kapsul'),
            ]),
            $this->catalogProduct('Hexabumin', 'hexabumin', 'HEX', 'TR183615831', ['nutrisi-dan-kebugaran', 'daya-tahan-tubuh'], [
                $this->catalogVariant('130 ml', 'SKU-WAL-HEX-130ML', 87000, 350, '130 ml'),
            ]),
            $this->catalogProduct('KurmaQu', 'kurmaqu', 'AQU', 'MD866612001100', ['nutrisi-dan-kebugaran'], [
                $this->catalogVariant('350 gram', 'SKU-WAL-AQU-350G', 60000, 450, '350 gram'),
            ]),
            $this->catalogProduct('Madu Herbal Lambung Sehat', 'madu-herbal-lambung-sehat', 'MHL', 'TR213611811', ['pencernaan'], [
                $this->catalogVariant('250 gram', 'SKU-WAL-MHL-250G', 135000, 350, '250 gram'),
            ]),
            $this->catalogProduct('Minyak Sapu Jagat', 'minyak-sapu-jagat', 'SPJ', 'QD153613221', ['sendi-dan-otot'], [
                $this->catalogVariant('110 ml', 'SKU-WAL-SPJ-110ML', 124000, 220, '110 ml'),
            ]),
            $this->catalogProduct('Natural Oud Facial Wash', 'natural-oud-facial-wash', 'NOC', 'NA18231201970', ['kesehatan-kulit'], [
                $this->catalogVariant('50 ml', 'SKU-WAL-NOC-50ML', 150000, 120, '50 ml'),
            ]),
            $this->catalogProduct('Propolis SM', 'propolis-sm', 'PSM', 'TR193624961', ['daya-tahan-tubuh', 'pernapasan-dan-tenggorokan'], [
                $this->catalogVariant('1 Box (7 Botol @ 6 ml)', 'SKU-WAL-PSM-7X6ML', 95000, 150, '1 box isi 7 botol @ 6 ml'),
            ]),
            $this->catalogProduct('QNC Jelly Gamat', 'qnc-jelly-gamat', 'QNC', 'TR173609991', ['daya-tahan-tubuh', 'sendi-dan-otot'], [
                $this->catalogVariant('300 ml', 'SKU-WAL-QNC-300ML', 180000, 400, '300 ml'),
            ]),
            $this->catalogProduct('Kopi Radimax', 'radimax', 'RAD', 'TR193228081', ['vitalitas-pria'], [
                $this->catalogVariant('10 Sachet @ 25 gram', 'SKU-WAL-RAD-10X25G', 150000, 350, '10 sachet @ 25 gram'),
            ]),
            $this->catalogProduct('Etaku Goat Milk', 'etaku-goat-milk', 'KAM', 'TR193227981', ['nutrisi-dan-kebugaran'], [
                $this->catalogVariant('200 gram', 'SKU-WAL-KAM-200G', 80000, 500, '200 gram'),
            ]),
        ];
    }

    /**
     * @param  list<string>  $categories
     * @param  list<array{name: string, sku: string, price: int, weight_grams: int, catalog_content: string}>  $variants
     * @return array{name: string, slug: string, code: string, bpom_number: string, categories: list<string>, variants: list<array{name: string, sku: string, price: int, weight_grams: int, catalog_content: string}>}
     */
    private function catalogProduct(
        string $name,
        string $slug,
        string $code,
        string $bpomNumber,
        array $categories,
        array $variants,
    ): array {
        return [
            'name' => $name,
            'slug' => $slug,
            'code' => $code,
            'bpom_number' => $bpomNumber,
            'categories' => $categories,
            'variants' => $variants,
        ];
    }

    /**
     * @return array{name: string, sku: string, price: int, weight_grams: int, catalog_content: string}
     */
    private function catalogVariant(
        string $name,
        string $sku,
        int $price,
        int $weightGrams,
        string $catalogContent,
    ): array {
        return [
            'name' => $name,
            'sku' => $sku,
            'price' => $price,
            'weight_grams' => $weightGrams,
            'catalog_content' => $catalogContent,
        ];
    }
}
