<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'slug',
        'product_code',
        'bpom_number',
        'description',
        'product_type',
        'status',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function bundle(): HasOne
    {
        return $this->hasOne(ProductBundle::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(StoreProductListing::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Image conversions can be added when the frontend image requirements are finalized.
    }
}
