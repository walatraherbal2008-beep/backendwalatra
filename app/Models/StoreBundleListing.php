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

class StoreBundleListing extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (StoreBundleListing $listing): void {
            if ($listing->isDirty('store_id')) {
                throw ValidationException::withMessages([
                    'store_id' => 'Toko pemilik listing tidak dapat diubah setelah listing dibuat.',
                ]);
            }

            if ($listing->isDirty('product_bundle_id')) {
                throw ValidationException::withMessages([
                    'product_bundle_id' => 'Bundle master tidak dapat diubah setelah listing dibuat. Hapus listing ini dan buat listing baru untuk bundle lain.',
                ]);
            }
        });

        static::saving(function (StoreBundleListing $listing): void {
            if ($listing->status !== 'active') {
                return;
            }

            $bundle = $listing->bundle()->with('items.variant.product')->first();
            $store = $listing->store()->first();
            $itemsAreAvailable = $bundle
                && $bundle->items->isNotEmpty()
                && $bundle->items->every(fn ($item): bool => $item->variant?->status === 'active'
                    && $item->variant->product?->status === 'active'
                );

            if (! $bundle || $bundle->status !== 'active' || ! $itemsAreAvailable || ! $store || $store->status !== 'active') {
                throw ValidationException::withMessages([
                    'status' => 'Listing bundle hanya dapat diaktifkan jika bundle, seluruh komponen, dan toko aktif.',
                ]);
            }

            $listing->published_at ??= now();
        });
    }

    protected $fillable = [
        'store_id',
        'product_bundle_id',
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

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductBundle::class, 'product_bundle_id');
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
