<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        static::saving(function (ProductVariant $variant): void {
            if (! is_numeric($variant->price) || (float) $variant->price <= 0) {
                throw ValidationException::withMessages([
                    'price' => 'Harga varian harus lebih besar dari 0.',
                ]);
            }

            if (! is_numeric($variant->weight_grams) || (int) $variant->weight_grams < 1) {
                throw ValidationException::withMessages([
                    'weight_grams' => 'Berat varian minimal 1 gram.',
                ]);
            }

            $duplicateName = static::query()
                ->where('product_id', $variant->product_id)
                ->whereRaw('LOWER(name) = LOWER(?)', [$variant->name])
                ->when($variant->exists, fn ($query) => $query->whereKeyNot($variant->getKey()))
                ->exists();

            if ($duplicateName) {
                throw ValidationException::withMessages([
                    'name' => 'Nama varian sudah digunakan pada produk ini.',
                ]);
            }
        });

        static::updating(function (ProductVariant $variant): void {
            if ($variant->isDirty('sku')) {
                throw ValidationException::withMessages([
                    'sku' => 'SKU varian tidak dapat diubah setelah dibuat.',
                ]);
            }

            if ($variant->isDirty('product_id')) {
                throw ValidationException::withMessages([
                    'product_id' => 'Produk induk varian tidak dapat diubah setelah dibuat.',
                ]);
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
            'weight_grams' => 'integer',
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

    public function warehouseAssignments(): HasMany
    {
        return $this->hasMany(WarehouseProductVariant::class);
    }
}
