<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BundleDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'price' => $this->price,
            'items' => BundleItemsResource::collection($this->items),
            // Pakai instance bundle yang sama (items.variant.product sudah
            // eager) agar fallback gambar master di tiap offer tidak N+1.
            'stores' => BundleStoreOfferResource::collection(
                $this->storeListings->each(
                    fn ($listing) => $listing->setRelation('bundle', $this->resource),
                ),
            ),
        ];
    }
}
