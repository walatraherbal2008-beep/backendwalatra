<?php

namespace Tests\Concerns;

use App\Models\Cart;
use App\Services\Marketplace\CheckoutPricing;

trait WithCheckoutToken
{
    /** Signed token fixture for controller tests that isolate stock, shipping, or media failures. */
    private function checkoutPayload(array $payload, string $warehouseCode = 'WH-TASIK'): array
    {
        if (array_key_exists('checkout_token', $payload)) {
            return $payload;
        }
        $items = Cart::where('user_id', auth()->id())->with('items')->first()?->items ?? collect();
        if (isset($payload['cart_item_ids'])) {
            $items = $items->whereIn('id', $payload['cart_item_ids']);
        }
        $payload['checkout_token'] = app(CheckoutPricing::class)->token(auth()->id(), $items, $warehouseCode);

        return $payload;
    }
}
