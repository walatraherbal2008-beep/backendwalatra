<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BundleStoreOfferResource extends JsonResource
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
            'images' => $this->getMedia('images')->map(fn ($media): array => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'name' => $media->name,
                'order' => $media->order_column,
            ])->values(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
