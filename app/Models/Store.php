<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Store extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected static function booted(): void
    {
        static::creating(function (Store $store): void {
            if (blank($store->slug)) {
                $store->slug = static::generateUniqueSlug($store->name);
            }

            if (blank($store->code)) {
                $store->code = static::generateUniqueCode();
            }
        });
    }

    protected static function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    protected static function generateUniqueCode(): string
    {
        do {
            $code = 'TOKO-'.Str::upper(Str::random(8));
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    protected $fillable = [
        'owner_user_id',
        'name',
        'description',
        'contact_phone',
        'contact_email',
        'province',
        'city',
        'district',
        'postal_code',
        'address',
        'latitude',
        'longitude',
        'status',
        'email_verified_at',
        'approved_at',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'opened_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(StoreProductListing::class);
    }

    public function bundleListings(): HasMany
    {
        return $this->hasMany(StoreBundleListing::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'store_product_listings')
            ->withPivot(['status', 'sort_order', 'published_at'])
            ->withTimestamps();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
        $this->addMediaCollection('banner')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Image conversions can be added when the frontend image requirements are finalized.
    }
}
