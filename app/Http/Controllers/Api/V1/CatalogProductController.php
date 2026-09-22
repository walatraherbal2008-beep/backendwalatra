<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogProductIndexRequest;
use App\Http\Resources\Api\V1\ProductDetailResource;
use App\Http\Resources\Api\V1\ProductListingResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class CatalogProductController extends Controller
{
    public function index(CatalogProductIndexRequest $request): AnonymousResourceCollection
    {
        $query = $this->activeListingQuery();
        $search = trim($request->string('q')->toString());
        $sort = $request->string('sort', 'relevance')->toString();

        $this->applySearch($query, $search);

        $query
            ->when($request->filled('store'), function (Builder $listingQuery) use ($request): void {
                $storeSlug = $request->string('store')->toString();

                $listingQuery->whereHas(
                    'store',
                    fn (Builder $storeQuery): Builder => $storeQuery->where('slug', $storeSlug),
                );
            })
            ->when($request->filled('category'), function (Builder $listingQuery) use ($request): void {
                $categorySlug = $request->string('category')->toString();

                $listingQuery->whereHas(
                    'product.categories',
                    fn (Builder $categoryQuery): Builder => $categoryQuery
                        ->where('categories.status', 'active')
                        ->where('categories.slug', $categorySlug),
                );
            })
            ->when($request->filled('min_price') || $request->filled('max_price'), function (Builder $listingQuery) use ($request): void {
                $listingQuery->whereHas(
                    'product.variants',
                    function (Builder $variantQuery) use ($request): Builder {
                        return $variantQuery
                            ->where('status', 'active')
                            ->when(
                                $request->filled('min_price'),
                                fn (Builder $query): Builder => $query->where('price', '>=', (float) $request->input('min_price')),
                            )
                            ->when(
                                $request->filled('max_price'),
                                fn (Builder $query): Builder => $query->where('price', '<=', (float) $request->input('max_price')),
                            );
                    },
                );
            });

        if ($sort === 'relevance') {
            $this->applyMarketplaceRanking($query, $search);
        } else {
            $this->applySort($query, $sort);
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 50);

        return ProductListingResource::collection($query->paginate($perPage))
            ->additional([
                'success' => true,
                'message' => 'Katalog produk berhasil diambil.',
            ]);
    }

    public function show(string $slug): JsonResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->whereHas('variants', fn (Builder $query): Builder => $query->where('status', 'active'))
            ->with([
                'variants' => fn ($query) => $query->where('status', 'active')->orderBy('id'),
                'categories' => fn ($query) => $query->where('status', 'active')->orderBy('sort_order'),
                'listings' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('store', fn (Builder $storeQuery): Builder => $storeQuery->where('status', 'active'))
                    ->with(['store', 'media']),
            ])
            ->first();

        abort_if(! $product, 404, 'Produk tidak ditemukan.');

        return (new ProductDetailResource($product))->additional([
            'success' => true,
            'message' => 'Detail produk berhasil diambil.',
        ]);
    }

    private function activeListingQuery(): Builder
    {
        return StoreProductListing::query()
            ->where('store_product_listings.status', 'active')
            ->whereHas('product', fn (Builder $query): Builder => $query->where('status', 'active'))
            ->whereHas('product.variants', fn (Builder $query): Builder => $query->where('status', 'active'))
            ->whereHas('store', fn (Builder $query): Builder => $query->where('status', 'active'))
            ->with([
                'product.variants' => fn ($query) => $query->where('status', 'active')->orderBy('id'),
                'product.categories' => fn ($query) => $query->where('status', 'active')->orderBy('sort_order'),
                'store',
                'media',
            ]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        // Token-AND konsisten dengan unified /catalog: tiap kata wajib cocok,
        // boleh beda urutan dan beda field. Max 10 token.
        $tokens = collect(preg_split('/\s+/', $search) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn ($token): bool => $token !== '')
            ->take(10)
            ->values();

        foreach ($tokens as $token) {
            $like = '%'.$token.'%';

            $query->where(function (Builder $listingQuery) use ($like): void {
                $listingQuery
                    ->whereHas('product', function (Builder $productQuery) use ($like): void {
                        $productQuery
                            ->whereLike('name', $like)
                            ->orWhereLike('product_code', $like)
                            // SKU + nama varian (misal "100ml").
                            ->orWhereHas('variants', fn (Builder $variantQuery): Builder => $variantQuery
                                ->whereLike('sku', $like)
                                ->orWhereLike('name', $like))
                            // Nama kategori aktif.
                            ->orWhereHas('categories', fn (Builder $categoryQuery): Builder => $categoryQuery
                                ->where('categories.status', 'active')
                                ->whereLike('categories.name', $like));
                    })
                    ->orWhereHas('store', fn (Builder $storeQuery): Builder => $storeQuery->whereLike('name', $like))
                    // Deskripsi etalase toko.
                    ->orWhereLike('store_product_listings.description', $like);
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql' || mb_strlen($search) < 2) {
            return;
        }

        $like = "%{$search}%";
        $prefix = "{$search}%";

        $query
            ->join('products as search_products', 'search_products.id', '=', 'store_product_listings.product_id')
            ->join('stores as search_stores', 'search_stores.id', '=', 'store_product_listings.store_id')
            ->select('store_product_listings.*')
            ->where(function (Builder $listingQuery) use ($search, $like): void {
                $listingQuery
                    ->whereRaw('search_products.name ILIKE ?', [$like])
                    ->orWhereRaw('search_products.product_code ILIKE ?', [$like])
                    ->orWhereRaw('search_stores.name ILIKE ?', [$like])
                    ->orWhereExists(function ($variantQuery) use ($search, $like): void {
                        $variantQuery
                            ->selectRaw('1')
                            ->from('product_variants as search_variants')
                            ->whereColumn('search_variants.product_id', 'search_products.id')
                            ->where(function ($query) use ($search, $like): void {
                                $query
                                    ->whereRaw('search_variants.sku ILIKE ?', [$like])
                                    ->orWhereRaw('search_variants.sku % ?', [$search]);
                            });
                    })
                    ->orWhereRaw('search_products.name % ?', [$search])
                    ->orWhereRaw('search_products.product_code % ?', [$search])
                    ->orWhereRaw('search_stores.name % ?', [$search]);
            })
            ->selectRaw(
                '(CASE
                    WHEN LOWER(search_products.name) = LOWER(?) THEN 100
                    WHEN LOWER(search_products.name) LIKE LOWER(?) THEN 80
                    WHEN LOWER(search_products.name) LIKE LOWER(?) THEN 50
                    WHEN LOWER(search_products.product_code) = LOWER(?) THEN 70
                    ELSE 0
                END
                + (similarity(search_products.name, ?) * 30)
                + (similarity(search_stores.name, ?) * 10)
                + COALESCE((SELECT MAX(similarity(search_variants_score.sku, ?))
                    FROM product_variants as search_variants_score
                    WHERE search_variants_score.product_id = search_products.id), 0) * 20
                ) AS search_relevance',
                [$search, $prefix, $like, $search, $search, $search, $search],
            );
    }

    private function applyMarketplaceRanking(Builder $query, string $search): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $query->latest('store_product_listings.created_at');

            return;
        }

        $productTable = $search === '' ? 'ranking_products' : 'search_products';

        if ($search === '') {
            $query
                ->join('products as ranking_products', 'ranking_products.id', '=', 'store_product_listings.product_id')
                ->select('store_product_listings.*');
        }

        $searchScore = $search === '' ? '100' : 'LEAST(COALESCE((CASE
            WHEN LOWER(search_products.name) = LOWER(?) THEN 100
            WHEN LOWER(search_products.name) LIKE LOWER(?) THEN 80
            WHEN LOWER(search_products.name) LIKE LOWER(?) THEN 50
            WHEN LOWER(search_products.product_code) = LOWER(?) THEN 70
            ELSE 0
        END
        + (similarity(search_products.name, ?) * 30)
        + (similarity(search_stores.name, ?) * 10)
        + COALESCE((SELECT MAX(similarity(search_variants_score.sku, ?))
            FROM product_variants as search_variants_score
            WHERE search_variants_score.product_id = search_products.id), 0) * 20), 0), 100)';

        $qualityScore = "(
            CASE WHEN EXISTS (
                SELECT 1 FROM media
                WHERE media.model_type = 'App\\Models\\StoreProductListing'
                  AND media.model_id = store_product_listings.id
                  AND media.collection_name = 'images'
            ) THEN 25 ELSE 0 END
            + CASE WHEN COALESCE(store_product_listings.description, '') <> '' THEN 20 ELSE 0 END
            + CASE WHEN EXISTS (
                SELECT 1 FROM category_product
                WHERE category_product.product_id = {$productTable}.id
            ) THEN 15 ELSE 0 END
            + CASE WHEN COALESCE({$productTable}.bpom_number, '') <> '' THEN 15 ELSE 0 END
            + 25
        )";

        $freshnessScore = "(
            CASE
                WHEN COALESCE(store_product_listings.published_at, store_product_listings.created_at) >= NOW() - INTERVAL '7 days' THEN 100
                WHEN COALESCE(store_product_listings.published_at, store_product_listings.created_at) >= NOW() - INTERVAL '30 days' THEN 60
                ELSE 20
            END
        )";

        $query
            ->selectRaw("{$qualityScore} AS listing_quality_score")
            ->selectRaw("{$freshnessScore} AS freshness_score")
            ->selectRaw(
                "(({$searchScore} * 0.70) + ({$qualityScore} * 0.20) + ({$freshnessScore} * 0.10)) AS marketplace_score",
                $search === '' ? [] : [$search, "{$search}%", "%{$search}%", $search, $search, $search, $search],
            )
            ->orderByDesc('marketplace_score')
            ->orderBy('store_product_listings.sort_order')
            ->latest('store_product_listings.created_at');
    }

    private function applySort(Builder $query, string $sort): void
    {
        if (in_array($sort, ['name_asc', 'name_desc'], true)) {
            $query
                ->join('products as catalog_products', 'catalog_products.id', '=', 'store_product_listings.product_id');

            if ($query->getQuery()->columns === null) {
                $query->select('store_product_listings.*');
            }

            $query->orderBy('catalog_products.name', $sort === 'name_asc' ? 'asc' : 'desc');

            return;
        }

        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $query
                ->join('products as catalog_products', 'catalog_products.id', '=', 'store_product_listings.product_id');

            if ($query->getQuery()->columns === null) {
                $query->select('store_product_listings.*');
            }

            $query->orderBy(
                ProductVariant::query()
                    ->select('price')
                    ->whereColumn('product_variants.product_id', 'catalog_products.id')
                    ->where('product_variants.status', 'active')
                    ->orderBy('product_variants.price')
                    ->limit(1),
                $sort === 'price_asc' ? 'asc' : 'desc',
            );

            return;
        }

        $query->latest('store_product_listings.created_at');
    }
}
