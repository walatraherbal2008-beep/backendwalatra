<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items;

        return [
            'id' => $this->id,
            'items' => $items->map(fn ($item): array => [
                'id' => $item->id,
                'listing_id' => $item->store_product_listing_id,
                'variant_id' => $item->product_variant_id,
                'product' => [
                    'id' => $item->variant->product->id,
                    'name' => $item->variant->product->name,
                    'slug' => $item->variant->product->slug,
                ],
                'store' => [
                    'id' => $item->listing->store->id,
                    'name' => $item->listing->store->name,
                    'slug' => $item->listing->store->slug,
                ],
                'variant' => [
                    'id' => $item->variant->id,
                    'name' => $item->variant->name,
                    'sku' => $item->variant->sku,
                ],
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => number_format((float) $item->unit_price * $item->quantity, 2, '.', ''),
            ])->values(),
            'total_items' => $items->sum('quantity'),
            'subtotal' => number_format((float) $items->sum(fn ($item): float => (float) $item->unit_price * $item->quantity), 2, '.', ''),
        ];
    }
}
