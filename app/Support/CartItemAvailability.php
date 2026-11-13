<?php

namespace App\Support;

use App\Models\CartItem;
use App\Models\ProductVariant;

final class CartItemAvailability
{
    public static function available(CartItem $item): bool
    {
        $listing = $item->isBundle() ? $item->bundleListing : $item->listing;
        if (! $listing || $listing->trashed() || $listing->status !== 'active'
            || ! $listing->store?->isEligibleForMarketplace()) {
            return false;
        }

        if (! $item->isBundle()) {
            return self::variantAvailable($item->variant)
                && $item->variant->product_id === $listing->product_id;
        }

        $bundle = $listing->bundle;

        return $bundle !== null && ! $bundle->trashed() && $bundle->status === 'active'
            && (float) $bundle->price > 0 && $bundle->items->isNotEmpty()
            && $bundle->items->every(fn ($component): bool => $component->quantity >= 1
                && self::variantAvailable($component->variant));
    }

    private static function variantAvailable(?ProductVariant $variant): bool
    {
        return $variant !== null && ! $variant->trashed() && $variant->status === 'active'
            && $variant->product !== null && ! $variant->product->trashed()
            && $variant->product->status === 'active';
    }
}
