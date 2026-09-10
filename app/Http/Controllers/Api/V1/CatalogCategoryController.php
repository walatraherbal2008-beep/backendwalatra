<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CatalogCategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->where('status', 'active')
            ->whereNull('parent_id')
            ->withCount(['products' => fn (Builder $query): Builder => $this->activeCatalogProducts($query)])
            ->with([
                'children' => fn ($query) => $query
                    ->where('status', 'active')
                    ->withCount(['products' => fn (Builder $productQuery): Builder => $this->activeCatalogProducts($productQuery)])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CatalogCategoryResource::collection($categories)->additional([
            'success' => true,
            'message' => 'Daftar kategori berhasil diambil.',
        ]);
    }

    public function show(string $slug): JsonResource
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->withCount(['products' => fn (Builder $query): Builder => $this->activeCatalogProducts($query)])
            ->with([
                'parent',
                'children' => fn ($query) => $query
                    ->where('status', 'active')
                    ->withCount(['products' => fn (Builder $productQuery): Builder => $this->activeCatalogProducts($productQuery)])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->first();

        abort_if(! $category, 404, 'Kategori tidak ditemukan.');

        return (new CatalogCategoryResource($category))->additional([
            'success' => true,
            'message' => 'Detail kategori berhasil diambil.',
        ]);
    }

    private function activeCatalogProducts(Builder $query): Builder
    {
        return $query
            ->where('products.status', 'active')
            ->whereHas('listings', function (Builder $listingQuery): void {
                $listingQuery
                    ->where('store_product_listings.status', 'active')
                    ->whereHas('store', fn (Builder $storeQuery): Builder => $storeQuery->where('stores.status', 'active'));
            });
    }
}
