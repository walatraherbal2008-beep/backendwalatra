<?php

namespace App\Http\Resources\Api\V1;

use App\Support\ListingImageConversions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductStoreOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'listing_id' => $this->id,
            'store' => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'code' => $this->store->code,
                'city' => $this->store->city,
                'province' => $this->store->province,
            ],
            'description' => $this->description,
            'images' => $this->getMedia('images')->map(fn ($media): array => array_merge(
                ['id' => $media->id, 'name' => $media->name, 'order' => $media->order_column],
                ListingImageConversions::urls($media),
            ))->values(),
            'published_at' => $this->published_at,
        ];
    }
}
