<?php

namespace App\Http\Resources\Api\V1;

use App\Support\CartItemAvailability;
use App\Support\CartItemImage;
use App\Support\CartSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    private array $selectedIds = [];

    public function withSelection(array $ids): static
    {
        $this->selectedIds = $ids;

        return $this;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }

    public function toArray(Request $request): array
    {
        $items = $this->items;

        return [
            'id' => $this->id,
            'items' => $items->map(fn ($item): array => [
                'id' => $item->id,
                'type' => $item->item_type,
                'is_available' => CartItemAvailability::available($item),
                'availability_message' => CartItemAvailability::available($item) ? null : 'Tidak tersedia',
                'image_url' => CartItemImage::resolve($item)?->getUrl(),
                'listing_id' => $item->store_product_listing_id,
                'bundle_listing_id' => $item->store_bundle_listing_id,
                'variant_id' => $item->product_variant_id,
                'product' => $item->isBundle() || ! $item->variant?->product ? null : [
                    'id' => $item->variant->product->id,
                    'name' => $item->variant->product->name,
                    'slug' => $item->variant->product->slug,
                ],
                'bundle' => $item->isBundle() && $item->bundleListing?->bundle ? [
                    'id' => $item->bundleListing->bundle->id,
                    'name' => $item->bundleListing->bundle->name,
                    'slug' => $item->bundleListing->bundle->slug,
                    'items' => BundleItemsResource::collection($item->bundleListing->bundle->items),
                ] : null,
                'store' => ! ($item->isBundle() ? $item->bundleListing : $item->listing)?->store ? null : [
                    'id' => ($item->isBundle() ? $item->bundleListing : $item->listing)->store->id,
                    'name' => ($item->isBundle() ? $item->bundleListing : $item->listing)->store->name,
                    'slug' => ($item->isBundle() ? $item->bundleListing : $item->listing)->store->slug,
                ],
                'variant' => $item->isBundle() || ! $item->variant ? null : [
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
            'selection' => CartSelection::summary($items, $this->selectedIds),
        ];
    }
}
