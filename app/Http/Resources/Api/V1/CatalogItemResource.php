<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'listing_id' => (int) $this->listing_id,
            'id' => (int) $this->item_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'price' => $this->type === 'product'
                ? ['min' => $this->price_min, 'max' => $this->price_max]
                : ['value' => $this->price_min],
            'image_url' => $this->image_url,
            'store' => [
                'id' => (int) $this->store_id,
                'name' => $this->store_name,
                'slug' => $this->store_slug,
                'city' => $this->city,
                'province' => $this->province,
            ],
            'published_at' => $this->published_at,
        ];
    }
}
