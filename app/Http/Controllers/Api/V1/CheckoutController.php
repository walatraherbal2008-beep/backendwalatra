<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\WarehouseStockProvider;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function preview(Request $request, WarehouseStockProvider $warehouse): JsonResponse
    {
        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'items.variant.product',
                'items.listing.store',
                'items.bundleListing.store',
                'items.bundleListing.bundle.items.variant.product',
            ])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart masih kosong.',
            ], 422);
        }

        if ($cart->items->contains(fn (CartItem $item): bool => ! $this->cartItemIsAvailable($item))) {
            return response()->json([
                'success' => false,
                'message' => 'Sebagian item cart sudah tidak tersedia. Silakan perbarui cart.',
            ], 409);
        }

        $items = $this->stockItems($cart->items);

        $availability = $warehouse->checkAvailability($items);

        return response()->json([
            'success' => true,
            'message' => $availability['available']
                ? 'Ketersediaan stok berhasil divalidasi.'
                : 'Stok gudang belum dapat divalidasi.',
            'data' => [
                'checkout_available' => $availability['available'],
                'items' => $cart->items->map(fn ($item): array => [
                    'cart_item_id' => $item->id,
                    'type' => $item->item_type,
                    'sku' => $item->isBundle() ? null : $item->variant->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => number_format((float) $item->unit_price * $item->quantity, 2, '.', ''),
                ])->values(),
                'subtotal' => number_format((float) $cart->items->sum(
                    fn ($item): float => (float) $item->unit_price * $item->quantity,
                ), 2, '.', ''),
            ],
        ], $availability['available'] ? 200 : 503);
    }

    private function cartItemIsAvailable(CartItem $item): bool
    {
        if ($item->isBundle()) {
            return $item->bundleListing?->status === 'active'
                && $item->bundleListing->store?->isEligibleForMarketplace()
                && $item->bundleListing->bundle?->status === 'active'
                && $item->bundleListing->bundle->isPurchasable();
        }

        return $item->listing?->status === 'active'
            && $item->listing->store?->isEligibleForMarketplace()
            && $item->listing->product?->status === 'active'
            && $item->variant?->status === 'active'
            && $item->variant->product?->status === 'active';
    }

    private function stockItems($items): array
    {
        return $items
            ->flatMap(function (CartItem $item) {
                if (! $item->isBundle()) {
                    return collect([['sku' => $item->variant->sku, 'quantity' => $item->quantity]]);
                }

                return $item->bundleListing->bundle->items->map(fn ($component): array => [
                    'sku' => $component->variant->sku,
                    'quantity' => $component->quantity * $item->quantity,
                ]);
            })
            ->groupBy('sku')
            ->map(fn ($skuItems, string $sku): array => [
                'sku' => $sku,
                'quantity' => $skuItems->sum('quantity'),
            ])
            ->values()
            ->all();
    }
}
