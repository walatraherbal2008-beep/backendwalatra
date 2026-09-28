<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductBundleItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_bundle_id',
        'product_variant_id',
        'quantity',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductBundleItem $item): void {
            if (! is_numeric($item->quantity) || (int) $item->quantity < 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'quantity' => 'Jumlah komponen bundle minimal 1.',
                ]);
            }
        });
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'product_bundle_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
