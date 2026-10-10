<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\WarehouseStockProvider;
use App\Contracts\ShippingRateProvider;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Address;
use App\Services\Shipping\ShippingCostCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function preview(
        Request $request,
        WarehouseStockProvider $warehouse,
        ShippingRateProvider $shipping,
        ShippingCostCalculator $shippingCosts,
    ): JsonResponse
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

        $shippingData = null;
        $shippingAddress = $request->input('shipping_address');
        if ($request->filled('address_id')) {
            $shippingAddress = Address::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('address_id'))
                ->only(['name', 'phone', 'address', 'province', 'province_id', 'province_name', 'city', 'city_id', 'city_name', 'district', 'district_id', 'district_name', 'sub_district_id', 'sub_district_name', 'postal_code']);
        }

        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) $item->unit_price * $item->quantity);
        if (is_array($shippingAddress)) {
            $shippingQuote = $shipping->quote($shippingAddress, $items);
            $cost = $shippingCosts->calculate($shippingQuote, $shippingAddress, $subtotal, $this->weightGrams($cart->items));
            $shippingData = [
                'service' => $shippingQuote['service'],
                'eta_days' => $shippingQuote['eta_days'] ?? null,
                'gross_fee' => number_format($cost['gross_fee'], 2, '.', ''),
                'subsidy' => number_format($cost['subsidy'], 2, '.', ''),
                'fee' => number_format($cost['fee'], 2, '.', ''),
                'subsidy_rule_id' => $cost['rule_id'],
            ];
        }

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
                'shipping' => $shippingData,
                'total' => $shippingData
                    ? number_format($subtotal + (float) $shippingData['fee'], 2, '.', '')
                    : null,
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

    private function weightGrams($items): int
    {
        return (int) $items->sum(function (CartItem $item): int {
            if ($item->isBundle()) {
                return (int) $item->bundleListing->bundle->items->sum(
                    fn ($component): int => (int) $component->variant->weight_grams * $component->quantity * $item->quantity,
                );
            }

            return (int) $item->variant->weight_grams * $item->quantity;
        });
    }
}
