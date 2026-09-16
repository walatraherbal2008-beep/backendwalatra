<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Marketplace\ProductAvailabilityService;

class ProductObserver
{
    public function updated(Product $product): void
    {
        if ($product->wasChanged('status') && $product->status === 'inactive') {
            app(ProductAvailabilityService::class)->deactivateForProduct($product);
        }
    }

    public function deleted(Product $product): void
    {
        if (! $product->isForceDeleting()) {
            app(ProductAvailabilityService::class)->deactivateForProduct($product);
        }
    }
}
