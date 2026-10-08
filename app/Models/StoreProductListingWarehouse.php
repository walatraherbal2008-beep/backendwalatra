<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductListingWarehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_product_listing_id',
        'warehouse_code',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(StoreProductListing::class, 'store_product_listing_id');
    }
}
