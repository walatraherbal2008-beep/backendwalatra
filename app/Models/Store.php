<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

        static::saving(function (Store $store): void {
            if ($store->owner_user_id === null) {
                return;
            }

            $owner = $store->owner()->first();

            if (
                ! $owner
                || ! $owner->isWalatraMember()
                || ! $owner->hasRole('store_admin')
                || $owner->email_verified_at === null
            ) {
                throw ValidationException::withMessages([
                    'owner_user_id' => 'Pemilik toko harus merupakan member Walatra aktif yang telah diverifikasi dan memiliki akses seller.',
                ]);
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

    public function scopeEligibleForMarketplace(Builder $query): Builder
    {
        return $query->where('status', 'active')->where(function (Builder $storeQuery): void {
            $storeQuery
                ->whereNull('owner_user_id')
                ->orWhereHas('owner', function (Builder $ownerQuery): void {
                    $ownerQuery
                        ->where('user_type', 'customer')
                        ->where('status', 'active')
                        ->whereNotNull('email_verified_at')
                        ->whereHas('roles', fn (Builder $roleQuery): Builder => $roleQuery
                            ->where('name', 'store_admin')
                            ->where('guard_name', 'web'));
                });
        });
    }

    public function isEligibleForMarketplace(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return $this->owner_user_id === null || (
            $this->owner?->user_type === 'customer'
            && $this->owner->status === 'active'
            && $this->owner->email_verified_at !== null
            && $this->owner->hasRole('store_admin')
        );
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
