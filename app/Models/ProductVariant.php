<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (blank($variant->sku)) {
                $variant->sku = static::generateUniqueSku($variant);
            }
        });
    }

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'price',
        'weight_grams',
        'attributes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'attributes' => 'array',
        ];
    }

    protected static function generateUniqueSku(ProductVariant $variant): string
    {
        $product = $variant->product()->first();
        $productKey = $product?->product_code ?: $product?->name ?: 'PRODUCT';
        $baseSku = 'SKU-WAL-'.Str::upper(Str::slug($productKey, '-')).'-'.Str::upper(Str::slug($variant->name, '-'));
        $sku = $baseSku;
        $suffix = 2;

        while (static::withTrashed()->where('sku', $sku)->exists()) {
            $sku = $baseSku.'-'.$suffix++;
        }

        return $sku;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class);
    }
}
