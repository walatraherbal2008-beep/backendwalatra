<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogBundleIndexRequest;
use App\Http\Resources\Api\V1\BundleDetailResource;
use App\Http\Resources\Api\V1\BundleListingResource;
use App\Models\ProductBundle;
use App\Models\StoreBundleListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogBundleController extends Controller
{
    public function index(CatalogBundleIndexRequest $request): AnonymousResourceCollection
    {
        $query = $this->activeListingQuery()
            ->when($request->filled('store'), function (Builder $listingQuery) use ($request): void {
                $listingQuery->whereHas(
                    'store',
                    fn (Builder $storeQuery): Builder => $storeQuery->where('slug', $request->string('store')->toString()),
                );
            })
            ->when($request->filled('q'), function (Builder $listingQuery) use ($request): void {
                $search = '%'.$request->string('q')->trim().'%';

                $listingQuery->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('bundle', fn (Builder $bundleQuery): Builder => $bundleQuery
                            ->whereLike('name', $search)
                            ->orWhereLike('code', $search))
                        ->orWhereHas('store', fn (Builder $storeQuery): Builder => $storeQuery->whereLike('name', $search));
                });
            });

        $this->applySort($query, $request->string('sort', 'latest')->toString());

        return BundleListingResource::collection($query->paginate($request->integer('per_page', 15)))
            ->additional([
                'success' => true,
                'message' => 'Katalog bundle berhasil diambil.',
            ]);
    }

    public function show(string $slug): JsonResource
    {
        $bundle = ProductBundle::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with([
                'items.variant.product',
                'storeListings' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('store', fn (Builder $storeQuery): Builder => $storeQuery->where('status', 'active'))
                    ->with(['store', 'media']),
            ])
            ->first();

        abort_if(! $bundle || ! $bundle->isPurchasable(), 404, 'Bundle tidak ditemukan.');

        return (new BundleDetailResource($bundle))->additional([
            'success' => true,
            'message' => 'Detail bundle berhasil diambil.',
        ]);
    }

    private function activeListingQuery(): Builder
    {
        return StoreBundleListing::query()
            ->where('store_bundle_listings.status', 'active')
            ->whereHas('store', fn (Builder $query): Builder => $query->where('status', 'active'))
            ->whereHas('bundle', function (Builder $query): void {
                $query
                    ->where('status', 'active')
                    ->purchasable();
            })
            ->with([
                'bundle.items.variant.product',
                'store',
                'media',
            ]);
    }

    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'name_asc' || $sort === 'name_desc') {
            $query
                ->join('product_bundles as catalog_bundles', 'catalog_bundles.id', '=', 'store_bundle_listings.product_bundle_id')
                ->select('store_bundle_listings.*')
                ->orderBy('catalog_bundles.name', $sort === 'name_asc' ? 'asc' : 'desc');

            return;
        }

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $query
                ->join('product_bundles as catalog_bundles', 'catalog_bundles.id', '=', 'store_bundle_listings.product_bundle_id')
                ->select('store_bundle_listings.*')
                ->orderBy('catalog_bundles.price', $sort === 'price_asc' ? 'asc' : 'desc');

            return;
        }

        $query->latest('store_bundle_listings.created_at');
    }
}
