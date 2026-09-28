<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'item_type',
        'store_product_listing_id',
        'store_bundle_listing_id',
        'product_variant_id',
        'sku',
        'product_name',
        'variant_name',
        'store_name',
        'bundle_components',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'bundle_components' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function bundleListing(): BelongsTo
    {
        return $this->belongsTo(StoreBundleListing::class, 'store_bundle_listing_id');
    }
}
