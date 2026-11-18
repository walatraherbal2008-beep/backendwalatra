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
            'payment_provider' => $this->payment_provider,
            'simulation' => $this->payment_provider === 'simulation',
            'payment_required' => $this->status === 'pending_payment' && $this->payment_close_reason === null && ($this->payment_due_at === null || $this->payment_due_at->isFuture()),
            'payment_integration' => $this->payment_integration,
            'payment_due_at' => $this->payment_due_at?->toIso8601String(),
            'payment' => $this->whenLoaded('activePayment', fn () => new OrderPaymentResource($this->activePayment)),
            'payment_instructions' => $this->payment_provider === 'manual' && $this->status === 'pending_payment'
                ? config('checkout.manual.payment_instructions') : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'shipping_gross_fee' => $this->shipping_gross_fee,
            'shipping_subsidy' => $this->shipping_subsidy,
            'shipping_fee' => $this->shipping_fee,
            'shipping_service' => $this->shipping_service,
            'total' => $this->total,
            'shipping_address' => $this->shipping_address,
            'shipping_origin' => $this->shipping_origin,
            'stock_mode' => $this->stock_mode,
            'stock_check' => $this->stock_check,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'type' => $item->item_type,
                'image_url' => $item->getFirstMediaUrl('image') ?: null,
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
