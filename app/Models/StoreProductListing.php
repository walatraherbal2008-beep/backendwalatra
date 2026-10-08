<?php

namespace App\Models;

use App\Support\ListingImageConversions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class StoreProductListing extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (StoreProductListing $listing): void {
            if ($listing->isDirty('store_id')) {
                throw ValidationException::withMessages([
                    'store_id' => 'Toko pemilik listing tidak dapat diubah setelah listing dibuat.',
                ]);
            }

            if ($listing->isDirty('product_id')) {
                throw ValidationException::withMessages([
                    'product_id' => 'Produk master tidak dapat diubah setelah listing dibuat. Hapus listing ini dan buat listing baru untuk produk lain.',
                ]);
            }
        });

        static::saving(function (StoreProductListing $listing): void {
            if ($listing->status !== 'active') {
                return;
            }

            $product = $listing->product()->with('variants')->first();
            $store = $listing->store()->first();

            if (
                ! $product
                || $product->status !== 'active'
                || ! $product->variants->contains(fn (ProductVariant $variant): bool => $variant->status === 'active')
                || ! $store
                || $store->status !== 'active'
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Listing hanya dapat diaktifkan jika produk aktif, memiliki minimal satu varian aktif, dan toko aktif.',
                ]);
            }

            $listing->published_at ??= now();
        });
    }

    protected $fillable = [
        'store_id',
        'product_id',
        'description',
        'status',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseAssignments(): HasMany
    {
        return $this->hasMany(StoreProductListingWarehouse::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        ListingImageConversions::register($this);
    }
}
