<?php

namespace App\Support;

use App\Models\CartItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CartItemImage
{
    public static function resolve(CartItem $item): ?Media
    {
        if ($item->isBundle()) {
            if ($item->bundleListing === null) {
                return null;
            }

            return ListingImageConversions::gallery(
                $item->bundleListing,
                $item->bundleListing->bundle?->items->first()?->variant?->product,
            )->first();
        }

        return $item->variant?->getFirstMedia('image')
            ?? ($item->listing
                ? ListingImageConversions::gallery($item->listing, $item->variant?->product)->first()
                : $item->variant?->product?->getFirstMedia('images'));
    }
}
