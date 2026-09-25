<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogIndexRequest;
use App\Http\Resources\Api\V1\CatalogItemResource;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Support\ListingImageConversions;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function index(CatalogIndexRequest $request): AnonymousResourceCollection
    {
        $type = $request->string('type')->toString();
        $search = trim($request->string('q')->toString());
        $sort = $request->string('sort', $search === '' ? 'latest' : 'relevance')->toString();
        $queries = [];

        if ($type !== 'bundle') {
            $queries[] = $this->productQuery($request, $search);
        }

        if ($type !== 'product') {
            $queries[] = $this->bundleQuery($request, $search);
        }

        $query = array_shift($queries);

        foreach ($queries as $additionalQuery) {
            $query->unionAll($additionalQuery);
        }

        $catalogQuery = DB::query()->fromSub($query, 'catalog_items');

        match ($sort) {
            'relevance' => $catalogQuery->orderByDesc('relevance_score')->orderByDesc('created_at'),
            'name_asc' => $catalogQuery->orderBy('name')->orderByDesc('created_at'),
            'name_desc' => $catalogQuery->orderByDesc('name')->orderByDesc('created_at'),
            'price_asc' => $catalogQuery->orderBy('price_min')->orderByDesc('created_at'),
            'price_desc' => $catalogQuery->orderByDesc('price_min')->orderByDesc('created_at'),
            default => $catalogQuery->orderByDesc('created_at'),
        };

        $paginator = $catalogQuery->paginate(
            perPage: min(max($request->integer('per_page', 15), 1), 50),
            page: max($request->integer('page', 1), 1),
        );

        $this->attachImages($paginator->getCollection());

        return CatalogItemResource::collection($paginator)
            ->additional([
                'success' => true,
                'message' => 'Katalog gabungan berhasil diambil.',
            ]);
    }

    /**
     * Preload gambar etalase sekaligus untuk 1 halaman agar tidak N+1.
     *
     * @param  Collection<int, object>  $items
     */
    private function attachImages($items): void
    {
        $productListingIds = $items->where('type', 'product')->pluck('listing_id')->unique()->values();
        $bundleListingIds = $items->where('type', 'bundle')->pluck('listing_id')->unique()->values();

        $productImages = $productListingIds->isEmpty() ? collect() : StoreProductListing::query()
            ->whereIn('id', $productListingIds)
            ->with('media')
            ->get()
            ->mapWithKeys(fn (StoreProductListing $listing): array => [
                $listing->getKey() => ListingImageConversions::urls($listing->getFirstMedia('images')),
            ]);

        $bundleImages = $bundleListingIds->isEmpty() ? collect() : StoreBundleListing::query()
            ->whereIn('id', $bundleListingIds)
            ->with('media')
            ->get()
            ->mapWithKeys(fn (StoreBundleListing $listing): array => [
                $listing->getKey() => ListingImageConversions::urls($listing->getFirstMedia('images')),
            ]);

        // Fallback gambar master (pusat): etalase tanpa gambar tidak boleh
        // tampil kosong di katalog — pakai gambar produk pusat, atau gambar
        // produk pertama pada bundle untuk item bundle.
        $masterProductImages = $this->masterProductImages(
            $items->where('type', 'product')->pluck('item_id')->unique()->values(),
        );
        $masterBundleImages = $this->masterBundleImages(
            $items->where('type', 'bundle')->pluck('item_id')->unique()->values(),
        );

        $fallback = ['url' => null, 'thumb' => null, 'preview' => null, 'full' => null];

        foreach ($items as $item) {
            $isProduct = $item->type === 'product';
            $images = ($isProduct ? $productImages : $bundleImages)[$item->listing_id] ?? $fallback;

            if (($images['url'] ?? null) === null) {
                // Listing etalase tanpa gambar → fallback ke gambar master (pusat).
                $images = ($isProduct ? $masterProductImages : $masterBundleImages)[$item->item_id] ?? $fallback;
            }

            // image_url lama tetap (full original) agar FE lama tidak broken.
            $item->image_url = $images['url'];
            $item->image = $images;
        }
    }

    /**
     * Gambar master produk (pusat) untuk fallback listing etalase kosong.
     *
     * @param  Collection<int, int|string>  $productIds
     * @return Collection<int, array{url: ?string, thumb: ?string, preview: ?string, full: ?string}>
     */
    private function masterProductImages(Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $productIds)
            ->with('media')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => ListingImageConversions::urls($product->getFirstMedia('images')),
            ]);
    }

    /**
     * Fallback bundle: gambar master produk pertama pada bundle
     * (master bundle sendiri belum punya koleksi gambar).
     *
     * @param  Collection<int, int|string>  $bundleIds
     * @return Collection<int, array{url: ?string, thumb: ?string, preview: ?string, full: ?string}>
     */
    private function masterBundleImages(Collection $bundleIds): Collection
    {
        if ($bundleIds->isEmpty()) {
            return collect();
        }

        $firstProductPerBundle = ProductBundleItem::query()
            ->join('product_variants', 'product_variants.id', '=', 'product_bundle_items.product_variant_id')
            ->whereIn('product_bundle_items.product_bundle_id', $bundleIds)
            ->orderBy('product_bundle_items.id')
            ->get(['product_bundle_items.product_bundle_id', 'product_variants.product_id'])
            ->unique('product_bundle_id')
            ->mapWithKeys(fn (ProductBundleItem $item): array => [
                $item->product_bundle_id => $item->product_id,
            ]);

        if ($firstProductPerBundle->isEmpty()) {
            return collect();
        }

        $imagesByProductId = $this->masterProductImages(
            $firstProductPerBundle->values()->unique()->values(),
        );

        return $firstProductPerBundle->mapWithKeys(fn ($productId, $bundleId): array => [
            $bundleId => $imagesByProductId[$productId]
                ?? ['url' => null, 'thumb' => null, 'preview' => null, 'full' => null],
        ]);
    }

    private function productQuery(CatalogIndexRequest $request, string $search = ''): EloquentBuilder
    {
        $query = StoreProductListing::query()
            ->join('products', 'products.id', '=', 'store_product_listings.product_id')
            ->join('stores', 'stores.id', '=', 'store_product_listings.store_id')
            ->where('store_product_listings.status', 'active')
            ->where('products.status', 'active')
            ->where('stores.status', 'active')
            ->whereExists(fn (Builder $variant): Builder => $variant
                ->selectRaw('1')
                ->from('product_variants')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->where('product_variants.status', 'active'));

        $this->applyCommonFilters($query, $request, 'products', 'stores');

        if ($request->filled('category')) {
            $query->whereExists(fn (Builder $categoryQuery): Builder => $categoryQuery
                ->selectRaw('1')
                ->from('category_product')
                ->join('categories', 'categories.id', '=', 'category_product.category_id')
                ->whereColumn('category_product.product_id', 'products.id')
                ->where('categories.slug', $request->string('category')->toString())
                ->where('categories.status', 'active'));
        }

        return $query->select([
            DB::raw("'product' as type"),
            'store_product_listings.id as listing_id',
            'products.id as item_id',
            'products.name',
            'products.slug',
            'products.product_code as code',
            DB::raw('(select min(price) from product_variants where product_id = products.id and status = \'active\') as price_min'),
            DB::raw('(select max(price) from product_variants where product_id = products.id and status = \'active\') as price_max'),
            'stores.id as store_id',
            'stores.name as store_name',
            'stores.slug as store_slug',
            'stores.city',
            'stores.province',
            'store_product_listings.published_at',
            'store_product_listings.created_at',
            DB::raw($this->productRelevanceExpression($search).' as relevance_score'),
        ]);
    }

    private function bundleQuery(CatalogIndexRequest $request, string $search = ''): EloquentBuilder
    {
        $query = StoreBundleListing::query()
            ->join('product_bundles', 'product_bundles.id', '=', 'store_bundle_listings.product_bundle_id')
            ->join('stores', 'stores.id', '=', 'store_bundle_listings.store_id')
            ->where('store_bundle_listings.status', 'active')
            ->where('product_bundles.status', 'active')
            ->where('stores.status', 'active')
            ->whereExists(fn (Builder $item): Builder => $item
                ->selectRaw('1')
                ->from('product_bundle_items')
                ->join('product_variants', 'product_variants.id', '=', 'product_bundle_items.product_variant_id')
                ->join('products as bundle_products', 'bundle_products.id', '=', 'product_variants.product_id')
                ->whereColumn('product_bundle_items.product_bundle_id', 'product_bundles.id')
                ->where('product_variants.status', 'active')
                ->where('bundle_products.status', 'active'))
            ->whereNotExists(fn (Builder $item): Builder => $item
                ->selectRaw('1')
                ->from('product_bundle_items')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'product_bundle_items.product_variant_id')
                ->leftJoin('products as bundle_products', 'bundle_products.id', '=', 'product_variants.product_id')
                ->whereColumn('product_bundle_items.product_bundle_id', 'product_bundles.id')
                ->where(function (Builder $invalid): void {
                    $invalid
                        ->whereNull('product_variants.id')
                        ->orWhere('product_variants.status', '!=', 'active')
                        ->orWhereNull('bundle_products.id')
                        ->orWhere('bundle_products.status', '!=', 'active');
                }));

        $this->applyCommonFilters($query, $request, 'product_bundles', 'stores');

        if ($request->filled('category')) {
            $category = $request->string('category')->toString();
            $query->whereExists(fn (Builder $item): Builder => $item
                ->selectRaw('1')
                ->from('product_bundle_items as category_items')
                ->join('product_variants as category_variants', 'category_variants.id', '=', 'category_items.product_variant_id')
                ->join('category_product', 'category_product.product_id', '=', 'category_variants.product_id')
                ->join('categories', 'categories.id', '=', 'category_product.category_id')
                ->whereColumn('category_items.product_bundle_id', 'product_bundles.id')
                ->where('categories.slug', $category)
                ->where('categories.status', 'active'));
        }

        return $query->select([
            DB::raw("'bundle' as type"),
            'store_bundle_listings.id as listing_id',
            'product_bundles.id as item_id',
            'product_bundles.name',
            'product_bundles.slug',
            'product_bundles.code',
            'product_bundles.price as price_min',
            'product_bundles.price as price_max',
            'stores.id as store_id',
            'stores.name as store_name',
            'stores.slug as store_slug',
            'stores.city',
            'stores.province',
            'store_bundle_listings.published_at',
            'store_bundle_listings.created_at',
            DB::raw($this->bundleRelevanceExpression($search).' as relevance_score'),
        ]);
    }

    /**
     * Skor 0-100 lintas driver: exact > prefix > contains, plus kode/SKU,
     * toko, kualitas etalase, dan freshness. Kolom harus identik di kedua
     * sisi UNION agar bisa diurutkan di outer query.
     */
    private function productRelevanceExpression(string $search): string
    {
        $search = trim($search);

        if ($search === '') {
            return '0';
        }

        $pdo = DB::connection()->getPdo();
        $exact = $pdo->quote($search);
        $prefix = $pdo->quote($search.'%');
        $contains = $pdo->quote('%'.$search.'%');

        return 'COALESCE(('.
            "CASE WHEN products.name = {$exact} THEN 100 ".
            "WHEN products.name LIKE {$prefix} THEN 80 ".
            "WHEN products.name LIKE {$contains} THEN 50 ELSE 0 END".
            " + CASE WHEN products.product_code = {$exact} THEN 70 ".
            "WHEN products.product_code LIKE {$contains} THEN 30 ELSE 0 END".
            ' + CASE WHEN EXISTS (SELECT 1 FROM product_variants search_variants'.
            ' WHERE search_variants.product_id = products.id'.
            " AND (search_variants.sku = {$exact} OR search_variants.sku LIKE {$contains})) THEN 20 ELSE 0 END".
            " + CASE WHEN stores.name LIKE {$contains} THEN 10 ELSE 0 END".
            ' + CASE WHEN COALESCE(store_product_listings.description, \'\') <> \'\' THEN 8 ELSE 0 END'.
            ' + CASE WHEN EXISTS (SELECT 1 FROM media search_media'.
            " WHERE search_media.model_type = 'App\\\\Models\\\\StoreProductListing'".
            ' AND search_media.model_id = store_product_listings.id'.
            " AND search_media.collection_name = 'images') THEN 7 ELSE 0 END".
            '), 0)';
    }

    private function bundleRelevanceExpression(string $search): string
    {
        $search = trim($search);

        if ($search === '') {
            return '0';
        }

        $pdo = DB::connection()->getPdo();
        $exact = $pdo->quote($search);
        $prefix = $pdo->quote($search.'%');
        $contains = $pdo->quote('%'.$search.'%');

        return 'COALESCE(('.
            "CASE WHEN product_bundles.name = {$exact} THEN 100 ".
            "WHEN product_bundles.name LIKE {$prefix} THEN 80 ".
            "WHEN product_bundles.name LIKE {$contains} THEN 50 ELSE 0 END".
            " + CASE WHEN product_bundles.code = {$exact} THEN 70 ".
            "WHEN product_bundles.code LIKE {$contains} THEN 30 ELSE 0 END".
            ' + CASE WHEN EXISTS (SELECT 1 FROM product_bundle_items search_items'.
            ' JOIN product_variants search_variants ON search_variants.id = search_items.product_variant_id'.
            ' JOIN products search_products ON search_products.id = search_variants.product_id'.
            ' WHERE search_items.product_bundle_id = product_bundles.id'.
            " AND (search_variants.sku LIKE {$contains} OR search_products.name LIKE {$contains})) THEN 20 ELSE 0 END".
            " + CASE WHEN stores.name LIKE {$contains} THEN 10 ELSE 0 END".
            ' + CASE WHEN COALESCE(store_bundle_listings.description, \'\') <> \'\' THEN 8 ELSE 0 END'.
            ' + CASE WHEN EXISTS (SELECT 1 FROM media search_media'.
            " WHERE search_media.model_type = 'App\\\\Models\\\\StoreBundleListing'".
            ' AND search_media.model_id = store_bundle_listings.id'.
            " AND search_media.collection_name = 'images') THEN 7 ELSE 0 END".
            '), 0)';
    }

    private function applyCommonFilters(EloquentBuilder $query, CatalogIndexRequest $request, string $itemTable, string $storeTable): void
    {
        if ($request->filled('q')) {
            $keyword = trim($request->string('q')->toString());
            $listingTable = $itemTable === 'products' ? 'store_product_listings' : 'store_bundle_listings';
            $codeColumn = $itemTable === 'products' ? 'product_code' : 'code';

            // Token AND: "kronis lambung" tetap ketemu walau urutan beda.
            // Tiap token boleh cocok di field mana saja (nama/SKU/deskripsi/
            // varian/kategori/komponen-bundle). Satu grup OR per token,
            // digabung AND antar token.
            $tokens = collect(preg_split('/\s+/', $keyword) ?: [])
                ->map(fn ($token): string => trim((string) $token))
                ->filter(fn ($token): bool => $token !== '')
                ->take(10)
                ->values();

            foreach ($tokens as $token) {
                $search = '%'.$token.'%';
                $query->where(function (EloquentBuilder $searchQuery) use ($search, $keyword, $itemTable, $storeTable, $listingTable, $codeColumn): void {
                    $searchQuery
                        ->whereLike($itemTable.'.name', $search)
                        ->orWhereLike($itemTable.'.'.$codeColumn, $search)
                        ->orWhereLike($storeTable.'.name', $search)
                        ->orWhereLike($listingTable.'.description', $search)
                        ->orWhereExists(fn (Builder $variantQuery): Builder => $variantQuery
                            ->selectRaw('1')
                            ->from('product_variants as search_token_variants')
                            ->when(
                                $itemTable === 'products',
                                fn (Builder $variantLink): Builder => $variantLink->whereColumn('search_token_variants.product_id', 'products.id'),
                                fn (Builder $variantLink): Builder => $variantLink
                                    ->join('product_bundle_items as search_token_items', 'search_token_items.product_variant_id', '=', 'search_token_variants.id')
                                    ->whereColumn('search_token_items.product_bundle_id', 'product_bundles.id'),
                            )
                            ->where(function (Builder $variantMatch) use ($search): void {
                                $variantMatch
                                    ->whereLike('search_token_variants.sku', $search)
                                    ->orWhereLike('search_token_variants.name', $search);
                            }))
                        ->orWhereExists(fn (Builder $categoryQuery): Builder => $categoryQuery
                            ->selectRaw('1')
                            ->from('category_product as search_token_cp')
                            ->join('categories as search_token_categories', 'search_token_categories.id', '=', 'search_token_cp.category_id')
                            ->when(
                                $itemTable === 'products',
                                fn (Builder $categoryLink): Builder => $categoryLink->whereColumn('search_token_cp.product_id', 'products.id'),
                                fn (Builder $categoryLink): Builder => $categoryLink
                                    ->join('product_variants as search_token_cv', 'search_token_cv.product_id', '=', 'search_token_cp.product_id')
                                    ->join('product_bundle_items as search_token_ci', 'search_token_ci.product_variant_id', '=', 'search_token_cv.id')
                                    ->whereColumn('search_token_ci.product_bundle_id', 'product_bundles.id'),
                            )
                            ->where('search_token_categories.status', 'active')
                            ->whereLike('search_token_categories.name', $search));

                    // Nama produk komponen bundle ikut dalam grup OR token yang
                    // sama agar tidak membobol logika AND antar token.
                    if ($itemTable !== 'products' && mb_strlen($keyword) >= 2) {
                        $searchQuery->orWhereExists(fn (Builder $itemQuery): Builder => $itemQuery
                            ->selectRaw('1')
                            ->from('product_bundle_items as search_items')
                            ->join('product_variants as search_variants', 'search_variants.id', '=', 'search_items.product_variant_id')
                            ->join('products as search_products', 'search_products.id', '=', 'search_variants.product_id')
                            ->whereColumn('search_items.product_bundle_id', 'product_bundles.id')
                            ->whereLike('search_products.name', $search));
                    }
                });
            }
        }

        if ($request->filled('store')) {
            $query->where($storeTable.'.slug', $request->string('store')->toString());
        }

        if ($request->filled('min_price') || $request->filled('max_price')) {
            if ($itemTable === 'products') {
                $query->whereExists(fn (Builder $variant): Builder => $variant
                    ->selectRaw('1')->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.status', 'active')
                    ->when($request->filled('min_price'), fn (Builder $q): Builder => $q->where('price', '>=', $request->input('min_price')))
                    ->when($request->filled('max_price'), fn (Builder $q): Builder => $q->where('price', '<=', $request->input('max_price'))));
            } else {
                $query->when($request->filled('min_price'), fn (EloquentBuilder $q): EloquentBuilder => $q->where('product_bundles.price', '>=', $request->input('min_price')))
                    ->when($request->filled('max_price'), fn (EloquentBuilder $q): EloquentBuilder => $q->where('product_bundles.price', '<=', $request->input('max_price')));
            }
        }
    }
}
