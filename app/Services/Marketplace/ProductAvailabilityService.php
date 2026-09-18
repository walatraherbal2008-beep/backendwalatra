<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductAvailabilityService
{
    public function synchronizeProduct(Product $product): void
    {
        $hasActiveVariant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->exists();

        if (! $product->trashed() && $product->status === 'active' && ! $hasActiveVariant) {
            $product->update(['status' => 'inactive']);

            return;
        }

        if ($product->trashed() || $product->status !== 'active' || ! $hasActiveVariant) {
            $this->deactivateForProduct($product);
        }
    }

    public function deactivateForProduct(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $this->deactivateProductListings($product->id);
            $this->deactivateBundleListings($this->bundleIdsForProduct($product->id));
        });
    }

    public function syncAfterVariantChange(ProductVariant $variant): void
    {
        $product = Product::withTrashed()->find($variant->product_id);

        if (! $product) {
            return;
        }

        DB::transaction(function () use ($product, $variant): void {
            $hasActiveVariant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('status', 'active')
                ->exists();

            if ($product->status === 'active' && ! $hasActiveVariant) {
                $product->update(['status' => 'inactive']);

                return;
            }

            $this->deactivateBundleListings($this->bundleIdsForVariant($variant->id));

            if ($product->trashed() || $product->status !== 'active' || ! $hasActiveVariant) {
                $this->deactivateProductListings($product->id);
                $this->deactivateBundleListings($this->bundleIdsForProduct($product->id));
            }
        });
    }

    /**
     * @param  list<int>|Collection<int, int>  $bundleIds
     */
    private function deactivateBundleListings(iterable $bundleIds): void
    {
        $bundleIds = collect($bundleIds)->filter()->unique()->values();

        if ($bundleIds->isEmpty()) {
            return;
        }

        StoreBundleListing::query()
            ->whereIn('product_bundle_id', $bundleIds)
            ->where('status', 'active')
            ->update([
                'status' => 'inactive',
                'published_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function deactivateProductListings(int $productId): void
    {
        StoreProductListing::query()
            ->where('product_id', $productId)
            ->where('status', 'active')
            ->update([
                'status' => 'inactive',
                'published_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Includes soft-deleted variants and bundle items because this method is
     * also called after a soft delete event.
     *
     * @return Collection<int, int>
     */
    private function bundleIdsForProduct(int $productId)
    {
        $variantIds = ProductVariant::withTrashed()
            ->where('product_id', $productId)
            ->pluck('id');

        return ProductBundleItem::withTrashed()
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('product_bundle_id');
    }

    /**
     * @return Collection<int, int>
     */
    private function bundleIdsForVariant(int $variantId)
    {
        return ProductBundleItem::withTrashed()
            ->where('product_variant_id', $variantId)
            ->pluck('product_bundle_id');
    }
}
