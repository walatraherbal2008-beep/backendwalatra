<?php

namespace App\Services\Marketplace;

use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreListingReactivationService
{
    /**
     * Restore a soft-deleted product listing instead of creating a duplicate row.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrRestoreProductListing(array $data): StoreProductListing
    {
        return DB::transaction(function () use ($data): StoreProductListing {
            $listing = StoreProductListing::withTrashed()
                ->where('store_id', $data['store_id'])
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if (! $listing) {
                return StoreProductListing::create($data);
            }

            if (! $listing->trashed()) {
                throw ValidationException::withMessages([
                    'product_id' => 'Produk tersebut sudah terdaftar di toko ini.',
                ]);
            }

            return $this->restoreListing($listing, $data);
        });
    }

    /**
     * Restore a soft-deleted bundle listing instead of creating a duplicate row.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrRestoreBundleListing(array $data): StoreBundleListing
    {
        return DB::transaction(function () use ($data): StoreBundleListing {
            $listing = StoreBundleListing::withTrashed()
                ->where('store_id', $data['store_id'])
                ->where('product_bundle_id', $data['product_bundle_id'])
                ->lockForUpdate()
                ->first();

            if (! $listing) {
                return StoreBundleListing::create($data);
            }

            if (! $listing->trashed()) {
                throw ValidationException::withMessages([
                    'product_bundle_id' => 'Bundle tersebut sudah terdaftar di toko ini.',
                ]);
            }

            return $this->restoreListing($listing, $data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function restoreListing(Model $listing, array $data): Model
    {
        $listing->restore();
        $listing->fill($data);
        $listing->save();

        return $listing;
    }
}
