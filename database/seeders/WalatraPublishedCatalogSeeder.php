<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Services\Marketplace\StoreOwnerAssignmentService;
use App\Support\WalatraOriginalProductCatalog;
use App\Support\WalatraProductCategoryMap;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WalatraPublishedCatalogSeeder extends Seeder
{
    private const CATALOG_SOURCE = 'Daftar produk Walatra yang diberikan pengguna';

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
                $product = $this->product($productData, $store);

                $this->publishListing(
                    store: $store,
                    product: $product,
                    sortOrder: $sortOrder + 1,
                );
            }

            $this->deactivateObsoleteCatalogProducts($store);
            $this->publishBundles($store);
        });

        $this->command?->newLine();
        $this->command?->info('Daftar produk dasar Walatra berhasil disinkronkan dan dipublikasikan.');
        $this->command?->info('Tiga paket bundle berhasil dibuat dan dipublikasikan.');
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
            'description' => 'Toko demo untuk pengujian katalog dasar Walatra Herbal.',
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
     *     bpom_number: ?string,
     *     categories: list<string>,
     *     variants: list<array{name: string, sku: string, price: int, weight_grams: int, catalog_content: ?string}>
     * }  $data
     */
    private function product(array $data, Store $store): Product
    {
        $product = Product::withTrashed()
            ->where('product_code', $data['code'])
            ->first();

        if (! $product) {
            $slugConflict = Product::withTrashed()
                ->where('slug', $data['slug'])
                ->first();

            if ($slugConflict) {
                $this->archiveProductConflict($slugConflict, $store, $data['slug']);
            }

            $product = new Product;
            $product->forceFill(['product_code' => $data['code']]);
        } elseif ($product->trashed()) {
            $product->restore();
        }

        $slugConflict = $product->exists
            ? Product::withTrashed()
                ->where('slug', $data['slug'])
                ->where('id', '<>', $product->getKey())
                ->first()
            : null;

        if ($slugConflict) {
            $this->archiveProductConflict($slugConflict, $store, $data['slug']);
        }

        $product->forceFill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'bpom_number' => $data['bpom_number'] ?? $product->bpom_number,
            'status' => 'draft',
        ])->save();

        foreach ($data['variants'] as $variantData) {
            $this->variant($product, $variantData, count($data['variants']) === 1);
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('sku', array_column($data['variants'], 'sku'))
            ->update(['status' => 'inactive']);

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

    private function archiveProductConflict(Product $product, Store $store, string $canonicalSlug): void
    {
        $product->forceFill([
            'slug' => $canonicalSlug.'-legacy-'.$product->id,
            'status' => 'draft',
        ])->save();

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->update(['status' => 'inactive']);

        StoreProductListing::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->update(['status' => 'inactive']);
    }

    /**
     * @param  array{name: string, sku: string, price: int, weight_grams: int, catalog_content: ?string}  $data
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

        $attributes = $variant->attributes ?? [];

        if ($data['catalog_content'] !== null) {
            $attributes['catalog_content'] = $data['catalog_content'];
        }

        $weightGrams = $data['weight_grams'] > 0
            ? $data['weight_grams']
            : (int) ($variant->weight_grams ?? 0);

        $attributes['price_source'] = self::CATALOG_SOURCE;
        if ($weightGrams === 0) {
            $attributes['shipping_weight_status'] = 'unknown';
            $attributes['shipping_weight_estimated'] = false;
        }

        $variant->forceFill([
            'name' => $data['name'],
            'price' => $data['price'],
            'weight_grams' => $weightGrams,
            'attributes' => $attributes,
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

    private function deactivateObsoleteCatalogProducts(Store $store): void
    {
        $obsoleteProducts = Product::query()
            ->whereIn('product_code', ['LMT', 'MHL', 'NOC'])
            ->get();

        foreach ($obsoleteProducts as $product) {
            $product->forceFill(['status' => 'draft'])->save();
            $product->variants()->update(['status' => 'inactive']);

            StoreProductListing::query()
                ->where('store_id', $store->id)
                ->where('product_id', $product->id)
                ->update(['status' => 'inactive']);
        }
    }

    private function publishBundles(Store $store): void
    {
        foreach ($this->bundles() as $sortOrder => $bundleData) {
            $bundle = ProductBundle::withTrashed()
                ->where('slug', $bundleData['slug'])
                ->first();

            if (! $bundle) {
                $bundle = new ProductBundle;
            } elseif ($bundle->trashed()) {
                $bundle->restore();
            }

            $bundle->forceFill([
                'name' => $bundleData['name'],
                'slug' => $bundleData['slug'],
                'code' => $bundleData['code'],
                'description' => $bundleData['description'],
                'price' => $bundleData['price'],
                'status' => 'active',
            ])->save();

            $bundle->items()->withTrashed()->forceDelete();

            foreach ($bundleData['items'] as $itemData) {
                $product = Product::query()
                    ->where('slug', $itemData['product_slug'])
                    ->where('status', 'active')
                    ->firstOrFail();

                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->whereRaw('LOWER(name) = LOWER(?)', [$itemData['variant_name']])
                    ->where('status', 'active')
                    ->firstOrFail();

                $bundle->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $itemData['quantity'],
                ]);
            }

            $listing = StoreBundleListing::withTrashed()
                ->where('store_id', $store->id)
                ->where('product_bundle_id', $bundle->id)
                ->first();

            if (! $listing) {
                $listing = new StoreBundleListing;
                $listing->forceFill([
                    'store_id' => $store->id,
                    'product_bundle_id' => $bundle->id,
                ]);
            } elseif ($listing->trashed()) {
                $listing->restore();
            }

            $listing->forceFill([
                'description' => $bundleData['description'],
                'status' => 'active',
                'sort_order' => $sortOrder + 1,
                'published_at' => $listing->published_at ?? now(),
            ])->save();
        }
    }

    /**
     * Paket bundle memakai definisi bundle proyek yang sudah ada. Komponen
     * Kapsul Sehat Lambungku pada paket keluarga diganti Madu Herbal Lambung
     * Sehat karena hanya produk tersebut yang tersedia pada PDF sumber.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     code: string,
     *     description: string,
     *     price: int,
     *     items: list<array{product_slug: string, variant_name: string, quantity: int}>
     * }>
     */
    private function bundles(): array
    {
        return [
            [
                'name' => 'Paket Herbal Keluarga',
                'slug' => 'paket-herbal-keluarga',
                'code' => 'BND-WAL-KELUARGA',
                'description' => 'Paket produk Walatra untuk kebutuhan keluarga.',
                'price' => 350000,
                'items' => [
                    ['product_slug' => 'sehat-wanita-50-kapsul', 'variant_name' => 'SEHAT WANITA 50 KAPSUL', 'quantity' => 1],
                    ['product_slug' => 'kapsul-sehat-lambungku-30-kapsul', 'variant_name' => 'Kapsul Sehat Lambungku 30 kapsul', 'quantity' => 1],
                    ['product_slug' => 'propolis-sm', 'variant_name' => 'PROPOLIS SM', 'quantity' => 1],
                ],
            ],
            [
                'name' => 'Paket Herbal Sendi dan Otot',
                'slug' => 'paket-herbal-sendi-dan-otot',
                'code' => 'BND-WAL-SENDI-OTOT',
                'description' => 'Paket produk Walatra untuk perawatan sendi dan otot.',
                'price' => 300000,
                'items' => [
                    ['product_slug' => 'sendifit', 'variant_name' => 'SENDIFIT', 'quantity' => 1],
                    ['product_slug' => 'oilfit', 'variant_name' => 'OILFIT', 'quantity' => 1],
                    ['product_slug' => 'keloreena-50-kapsul', 'variant_name' => 'KELOREENA 50 KAPSUL', 'quantity' => 1],
                ],
            ],
            [
                'name' => 'Paket Herbal Daya Tahan',
                'slug' => 'paket-herbal-daya-tahan',
                'code' => 'BND-WAL-DAYA-TAHAN',
                'description' => 'Paket produk Walatra untuk mendukung daya tahan tubuh.',
                'price' => 390000,
                'items' => [
                    ['product_slug' => 'jelly-gamat-qnc', 'variant_name' => 'Jelly Gamat QnC', 'quantity' => 1],
                    ['product_slug' => 'bio-squalen', 'variant_name' => 'BIO SQUALEN', 'quantity' => 1],
                    ['product_slug' => 'hexabumin', 'variant_name' => 'Hexabumin', 'quantity' => 1],
                ],
            ],
        ];
    }

    /**
     * Identitas dan harga mengikuti daftar produk asli yang diberikan pengguna.
     * Metadata yang tidak tersedia di daftar tidak ditebak.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     code: string,
     *     bpom_number: ?string,
     *     categories: list<string>,
     *     variants: list<array{name: string, sku: string, price: int, weight_grams: int, catalog_content: ?string}>
     * }>
     */
    private function catalog(): array
    {
        $categoryMap = WalatraProductCategoryMap::map();

        return array_map(fn (array $product): array => [
            'name' => $product['name'],
            'slug' => $product['slug'],
            'code' => $product['code'],
            'bpom_number' => null,
            'categories' => $categoryMap[$product['slug']] ?? [],
            'variants' => [$this->catalogVariant(
                $product['name'],
                $product['sku'],
                $product['price'],
                0,
                null,
            )],
        ], WalatraOriginalProductCatalog::products());
    }

    /**
     * @return array{name: string, sku: string, price: int, weight_grams: int, catalog_content: ?string}
     */
    private function catalogVariant(
        string $name,
        string $sku,
        int $price,
        int $weightGrams,
        ?string $catalogContent,
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
