<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Banner extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const POSITIONS = [
        'main' => 'Utama (besar)',
        'secondary_top' => 'Area 2 (kanan atas)',
        'secondary_bottom' => 'Area 3 (kanan bawah)',
    ];

    protected $fillable = [
        'name',
        'cta_url',
        'open_in_new_tab',
        'position',
        'status',
        'starts_at',
        'ends_at',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'open_in_new_tab' => false,
        'position' => 'main',
    ];

    protected function casts(): array
    {
        return [
            'open_in_new_tab' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Banner $banner): void {
            if (! in_array($banner->position, array_keys(self::POSITIONS), true)) {
                throw ValidationException::withMessages([
                    'position' => 'Area banner tidak valid.',
                ]);
            }

            $scheme = strtolower((string) parse_url($banner->cta_url, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https'], true)) {
                throw ValidationException::withMessages([
                    'cta_url' => 'URL CTA harus menggunakan http atau https.',
                ]);
            }

            if ($banner->starts_at !== null && $banner->ends_at !== null && $banner->ends_at->lt($banner->starts_at)) {
                throw ValidationException::withMessages([
                    'ends_at' => 'Waktu berakhir tidak boleh lebih awal dari waktu mulai.',
                ]);
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeCurrentlyVisible(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', 'active')
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('preview')
            ->fit(Fit::Max, 1600, 600)
            ->quality(85);
    }
}
