<?php

namespace App\Models;

use App\Support\ListingImageConversions;
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

            $bundleIsAvailable = $listing->bundle()->where('status', 'active')
                ->whereHas('items')
                ->whereDoesntHave('items', fn ($items) => $items->whereDoesntHave('variant', fn ($variants) => $variants
                    ->where('status', 'active')
                    ->whereHas('product', fn ($products) => $products->where('status', 'active'))))
                ->exists();

            if (! $bundleIsAvailable || ! $listing->store()->where('status', 'active')->exists()) {
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
        ListingImageConversions::register($this);
    }
}
