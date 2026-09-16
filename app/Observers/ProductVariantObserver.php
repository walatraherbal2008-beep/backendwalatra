<?php

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\Marketplace\ProductAvailabilityService;

class ProductVariantObserver
{
    public function updated(ProductVariant $variant): void
    {
        if ($variant->wasChanged('status') && $variant->status === 'inactive') {
            app(ProductAvailabilityService::class)->syncAfterVariantChange($variant);
        }
    }

    public function deleted(ProductVariant $variant): void
    {
        if (! $variant->isForceDeleting()) {
            app(ProductAvailabilityService::class)->syncAfterVariantChange($variant);
        }
    }
}
