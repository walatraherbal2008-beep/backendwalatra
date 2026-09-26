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
            ->with(['items.variant.product', 'items.listing.store'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart masih kosong.',
            ], 422);
        }

        $validItemCount = CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereHas('listing', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('product', fn ($productQuery) => $productQuery
                    ->where('status', 'active')))
            ->whereHas('variant', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('product', fn ($productQuery) => $productQuery
                    ->where('status', 'active')))
            ->whereHas('listing.store', fn ($query) => $query->eligibleForMarketplace())
            ->count();

        if ($validItemCount !== $cart->items->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Sebagian item cart sudah tidak tersedia. Silakan perbarui cart.',
            ], 409);
        }

        $items = $cart->items->map(fn ($item): array => [
            'sku' => $item->variant->sku,
            'quantity' => $item->quantity,
        ])->values()->all();

        $availability = $warehouse->checkAvailability($items);

        return response()->json([
            'success' => true,
            'message' => $availability['available']
                ? 'Ketersediaan stok berhasil divalidasi.'
                : 'Stok gudang belum dapat divalidasi.',
            'data' => [
                'checkout_available' => $availability['available'],
                'items' => $cart->items->map(fn ($item, int $index): array => [
                    'cart_item_id' => $item->id,
                    'sku' => $item->variant->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => number_format((float) $item->unit_price * $item->quantity, 2, '.', ''),
                    'stock' => $availability['items'][$index] ?? null,
                ])->values(),
                'subtotal' => number_format((float) $cart->items->sum(
                    fn ($item): float => (float) $item->unit_price * $item->quantity,
                ), 2, '.', ''),
            ],
        ], $availability['available'] ? 200 : 503);
    }
}
