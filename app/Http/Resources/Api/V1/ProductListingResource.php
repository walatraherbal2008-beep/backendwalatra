<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'listing_id' => $this->id,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'product_code' => $this->product->product_code,
                'bpom_number' => $this->product->bpom_number,
                'categories' => $this->product->categories->map(fn ($category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ])->values(),
            ],
            'store' => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'code' => $this->store->code,
                'city' => $this->store->city,
                'province' => $this->store->province,
            ],
            'description' => $this->description,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'images' => $this->getMedia('images')->map(fn ($media): array => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'name' => $media->name,
                'order' => $media->order_column,
            ])->values(),
            'variants' => $this->product->variants->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'weight_grams' => $variant->weight_grams,
                'attributes' => $variant->attributes,
            ])->values(),
            'published_at' => $this->published_at,
        ];
    }
}
