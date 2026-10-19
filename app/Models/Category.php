<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if (blank($category->slug)) {
                $category->slug = static::generateUniqueSlug($category->name);
            }
        });

        static::saving(function (Category $category): void {
            $category->validateParentHierarchy();
            $category->validateActiveProductUsage();
        });

        static::deleting(function (Category $category): void {
            $category->validateActiveProductUsage(isDeleting: true);
        });
    }

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    protected function validateParentHierarchy(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        $parentId = (int) $this->parent_id;
        $visited = $this->exists ? [$this->getKey() => true] : [];

        while ($parentId > 0) {
            if (isset($visited[$parentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Kategori induk tidak boleh membentuk siklus atau menjadi dirinya sendiri.',
                ]);
            }

            $visited[$parentId] = true;
            $parentId = (int) (static::withTrashed()->find($parentId)?->parent_id ?? 0);
        }
    }

    protected function validateActiveProductUsage(bool $isDeleting = false): void
    {
        $becomesInactive = $isDeleting || ($this->isDirty('status') && $this->status === 'inactive');

        if ($becomesInactive && $this->products()->where('products.status', 'active')->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Kategori tidak dapat dinonaktifkan atau dihapus karena masih digunakan produk aktif.',
            ]);
        }
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }
}
