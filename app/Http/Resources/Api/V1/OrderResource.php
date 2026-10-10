<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping_gross_fee' => $this->shipping_gross_fee,
            'shipping_subsidy' => $this->shipping_subsidy,
            'shipping_fee' => $this->shipping_fee,
            'shipping_service' => $this->shipping_service,
            'total' => $this->total,
            'shipping_address' => $this->shipping_address,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'type' => $item->item_type,
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'bundle_components' => $item->bundle_components,
                'store_name' => $item->store_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
