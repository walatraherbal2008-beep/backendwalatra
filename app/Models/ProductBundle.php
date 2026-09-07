<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductBundle extends Model
{
    use HasFactory;

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
        'description',
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
}
