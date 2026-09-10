<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductBundle extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ProductBundle $bundle): void {
            if (blank($bundle->slug)) {
                $bundle->slug = static::generateUniqueSlug($bundle->name);
            }

            if (blank($bundle->code)) {
                $bundle->code = static::generateUniqueCode();
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
            $code = 'BND-'.Str::upper(Str::random(8));
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    protected $fillable = [
        'name',
        'slug',
        'code',
        'price',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class);
    }

    public function storeListings(): HasMany
    {
        return $this->hasMany(StoreBundleListing::class, 'product_bundle_id');
    }

    public function isPurchasable(): bool
    {
        return $this->items->isNotEmpty()
            && $this->items->every(fn (ProductBundleItem $item): bool => $item->variant?->status === 'active'
                && $item->variant->product?->status === 'active'
            );
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query
            ->whereHas('items')
            ->whereDoesntHave('items', function (Builder $itemQuery): void {
                $itemQuery
                    ->whereDoesntHave('variant')
                    ->orWhereHas('variant', function (Builder $variantQuery): void {
                        $variantQuery->where(function (Builder $query): void {
                            $query
                                ->where('status', '!=', 'active')
                                ->orWhereDoesntHave('product')
                                ->orWhereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('status', '!=', 'active'));
                        });
                    });
            });
    }
}
