<?php

namespace Tests\Concerns;

use App\Contracts\ShippingRateProvider;
use App\Exceptions\ShippingUnavailableException;
use App\Models\Cart;
use App\Services\Marketplace\CheckoutPricing;
use App\Services\Marketplace\CheckoutReview;
use App\Services\Shipping\ShippingCostCalculator;

trait WithCheckoutToken
{
    /** Signed token fixture for controller tests that isolate stock, shipping, or media failures. */
    private function checkoutPayload(array $payload, string $warehouseCode = 'WH-TASIK'): array
    {
        if (array_key_exists('checkout_token', $payload)) {
            return $payload;
        }
        $items = Cart::where('user_id', auth()->id())->with([
            'items.variant.product',
            'items.bundleListing.bundle.items.variant.product',
        ])->first()?->items ?? collect();
        if (isset($payload['cart_item_ids'])) {
            $items = $items->whereIn('id', $payload['cart_item_ids']);
        }
        $review = app(CheckoutReview::class);
        $address = $review->address(
            auth()->id(),
            isset($payload['address_id']) ? (int) $payload['address_id'] : null,
            $payload['shipping_address'] ?? null,
        );
        try {
            $context = $review->quote(
                app(ShippingRateProvider::class),
                app(ShippingCostCalculator::class),
                $address['snapshot'],
                $address['fingerprint'],
                $items,
            )['fingerprint'];
        } catch (ShippingUnavailableException) {
            $subtotal = number_format((float) $items->sum(fn ($item): float => (float) $item->unit_price * $item->quantity), 2, '.', '');
            $context = [
                'shipping_address' => $address['fingerprint'],
                'subtotal' => $subtotal,
                'shipping' => ['service' => null, 'eta_days' => null, 'gross_fee' => '0.00', 'subsidy' => '0.00', 'fee' => '0.00', 'subsidy_rule_id' => null],
                'total' => $subtotal,
            ];
        }
        $payload['checkout_token'] = app(CheckoutPricing::class)->token(
            auth()->id(),
            $items,
            $warehouseCode,
            $context,
        );

        return $payload;
    }
}
