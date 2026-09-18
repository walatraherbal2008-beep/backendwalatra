<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogIndexRequest;
use App\Http\Resources\Api\V1\CatalogItemResource;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function index(CatalogIndexRequest $request): AnonymousResourceCollection
    {
        $type = $request->string('type')->toString();
        $queries = [];

        if ($type !== 'bundle') {
            $queries[] = $this->productQuery($request);
        }

        if ($type !== 'product') {
            $queries[] = $this->bundleQuery($request);
        }

        $query = array_shift($queries);

        foreach ($queries as $additionalQuery) {
            $query->unionAll($additionalQuery);
        }

        $catalogQuery = DB::query()->fromSub($query, 'catalog_items');
        $sort = $request->string('sort', 'latest')->toString();

        match ($sort) {
            'name_asc' => $catalogQuery->orderBy('name')->orderByDesc('created_at'),
            'name_desc' => $catalogQuery->orderByDesc('name')->orderByDesc('created_at'),
            'price_asc' => $catalogQuery->orderBy('price_min')->orderByDesc('created_at'),
            'price_desc' => $catalogQuery->orderByDesc('price_min')->orderByDesc('created_at'),
            default => $catalogQuery->orderByDesc('created_at'),
        };

        $paginator = $catalogQuery
            ->paginate(min(max($request->integer('per_page', 15), 1), 50));

        $paginator->getCollection()->transform(function (object $item): object {
            $listing = $item->type === 'product'
                ? StoreProductListing::find($item->listing_id)
                : StoreBundleListing::find($item->listing_id);

            $item->image_url = $listing?->getFirstMedia('images')?->getUrl();

            return $item;
        });

        return CatalogItemResource::collection($paginator)
            ->additional([
                'success' => true,
                'message' => 'Katalog gabungan berhasil diambil.',
            ]);
    }

    private function productQuery(CatalogIndexRequest $request): EloquentBuilder
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
        ]);
    }

    private function bundleQuery(CatalogIndexRequest $request): EloquentBuilder
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
        ]);
    }

    private function applyCommonFilters(EloquentBuilder $query, CatalogIndexRequest $request, string $itemTable, string $storeTable): void
    {
        if ($request->filled('q')) {
            $search = '%'.$request->string('q')->toString().'%';
            $query->where(function (EloquentBuilder $searchQuery) use ($search, $itemTable, $storeTable): void {
                $searchQuery
                    ->whereLike($itemTable.'.name', $search)
                    ->orWhereLike($itemTable.'.'.($itemTable === 'products' ? 'product_code' : 'code'), $search)
                    ->orWhereLike($storeTable.'.name', $search);
            });
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
