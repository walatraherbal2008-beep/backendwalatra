<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BundleItemsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product' => [
                'id' => $this->variant->product->id,
                'name' => $this->variant->product->name,
                'slug' => $this->variant->product->slug,
                'product_code' => $this->variant->product->product_code,
            ],
            'variant' => [
                'id' => $this->variant->id,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
                'price' => $this->variant->price,
                'weight_grams' => $this->variant->weight_grams,
            ],
            'quantity' => $this->quantity,
        ];
    }
}
