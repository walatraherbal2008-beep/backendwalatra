<?php

namespace App\Support;

use App\Models\CartItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CartItemImage
{
    public static function resolve(CartItem $item): ?Media
    {
        if ($item->isBundle()) {
            return ListingImageConversions::gallery(
                $item->bundleListing,
                $item->bundleListing->bundle->items->first()?->variant?->product,
            )->first();
        }

        return $item->variant->getFirstMedia('image')
            ?? ListingImageConversions::gallery($item->listing, $item->variant->product)->first();
    }
}
