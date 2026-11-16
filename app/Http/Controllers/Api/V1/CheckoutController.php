<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\ShippingRateProvider;
use App\Contracts\WarehouseStockProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutPreviewRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Marketplace\CheckoutPricing;
use App\Services\Marketplace\CheckoutReview;
use App\Services\Marketplace\CheckoutShippingOrigin;
use App\Services\Shipping\ShippingCostCalculator;
use App\Support\CartItemAvailability;
use App\Support\CartItemImage;
use App\Support\CartSelection;
use App\Support\CheckoutSimulation;
use App\Support\CheckoutStock;
use App\Support\ManualCheckout;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function preview(
        CheckoutPreviewRequest $request,
        WarehouseStockProvider $warehouse,
        ShippingRateProvider $shipping,
        ShippingCostCalculator $shippingCosts,
        CheckoutPricing $pricing,
        CheckoutReview $review,
        CheckoutShippingOrigin $origins,
    ): JsonResponse {
        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'items.variant.product.media',
                'items.variant.media',
                'items.listing.media',
                'items.bundleListing.media',
                'items.listing.store.owner.roles',
                'items.bundleListing.store.owner.roles',
                'items.bundleListing.bundle.items.variant.product.media',
                'items.bundleListing.bundle.items.variant.media',
            ])
            ->first();

        $selectedItems = $request->selectedCartItems($cart?->items ?? collect());
        $cart?->setRelation('items', $selectedItems);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart masih kosong.',
            ], 422);
        }

        if ($cart->items->contains(fn (CartItem $item): bool => ! CartItemAvailability::available($item))) {
            return response()->json([
                'success' => false,
                'code' => 'STALE_CART',
                'message' => 'Sebagian item cart sudah tidak tersedia. Silakan perbarui cart.',
            ], 409);
        }

        $priceChanges = $pricing->review($cart->items);
        $origin = $origins->resolve($cart->items);
        $items = $review->stockItems($cart->items);

        $manual = ManualCheckout::enabled();
        $unlimitedStock = CheckoutStock::unlimited();
        $availability = $unlimitedStock ? ['available' => true, 'items' => []] : $warehouse->checkAvailability($items);

        $addressContext = $review->address(
            $request->user()->id,
            $request->filled('address_id') ? $request->integer('address_id') : null,
            $request->validated('shipping_address'),
        );
        $shippingAddress = $addressContext['snapshot'] ?? null;
        $subtotal = (float) $cart->items->sum(fn ($item): float => (float) $item->unit_price * $item->quantity);
        $checkoutQuote = is_array($shippingAddress)
            ? $review->quote($shipping, $shippingCosts, $shippingAddress, $addressContext['fingerprint'], $cart->items)
            : null;

        return response()->json([
            'success' => true,
            'message' => $unlimitedStock
                ? ($manual ? 'Order dapat diajukan. Stok dan pembayaran akan diverifikasi admin.' : 'Order dapat diajukan. Stok belum diverifikasi.')
                : ($availability['available']
                ? 'Ketersediaan stok berhasil divalidasi.'
                : 'Stok gudang belum dapat divalidasi.'),
            'data' => [
                'checkout_token' => $availability['available'] && $checkoutQuote !== null
                    ? $pricing->token($request->user()->id, $cart->items, $origin['warehouse_code'], $checkoutQuote['fingerprint'])
                    : null,
                'price_changed' => $priceChanges !== [],
                'price_changes' => $priceChanges,
                'selection' => CartSelection::summary($cart->items, $cart->items->modelKeys()),
                'simulation' => CheckoutSimulation::enabled(),
                'payment_required' => ! CheckoutSimulation::enabled(),
                'payment_provider' => $manual ? 'manual' : (CheckoutSimulation::enabled() ? 'simulation' : 'midtrans'),
                'stock_mode' => CheckoutStock::mode(),
                'shipping_origin' => $origin,
                'stock_verification' => $unlimitedStock ? 'pending_manual' : (CheckoutSimulation::enabled() ? 'simulated' : 'external'),
                'checkout_available' => $availability['available'],
                'items' => $cart->items->map(fn ($item): array => [
                    'cart_item_id' => $item->id,
                    'type' => $item->item_type,
                    'image_url' => CartItemImage::resolve($item)?->getUrl(),
                    'sku' => $item->isBundle() ? null : $item->variant->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => number_format((float) $item->unit_price * $item->quantity, 2, '.', ''),
                ])->values(),
                'subtotal' => $checkoutQuote['subtotal_formatted'] ?? number_format($subtotal, 2, '.', ''),
                'shipping' => $checkoutQuote['shipping'] ?? null,
                'total' => $checkoutQuote['total'] ?? null,
            ],
        ], $availability['available'] ? 200 : 503);
    }
}
