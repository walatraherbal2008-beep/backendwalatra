<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Conversions can be added when the frontend image requirements are finalized.
    }
}
