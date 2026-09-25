<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ListingImageConversions
{
    public const THUMB_SIZE = 300;

    public const PREVIEW_SIZE = 800;

    public const FULL_SIZE = 1600;

    /**
     * Daftarkan 3 turunan etalase: thumb sync (langsung ada),
     * preview + full via queue redis.
     */
    public static function register(object $model): void
    {
        $model
            ->addMediaConversion('thumb')
            ->fit(Fit::Crop, self::THUMB_SIZE, self::THUMB_SIZE)
            ->quality(75)
            ->keepOriginalImageFormat()
            ->nonQueued();

        $model
            ->addMediaConversion('preview')
            ->fit(Fit::Max, self::PREVIEW_SIZE, self::PREVIEW_SIZE)
            ->quality(80)
            ->keepOriginalImageFormat();

        $model
            ->addMediaConversion('full')
            ->fit(Fit::Max, self::FULL_SIZE, self::FULL_SIZE)
            ->quality(85)
            ->keepOriginalImageFormat();
    }

    /**
     * Galeri gambar etalase: pakai gambar toko; kalau kosong, fallback ke
     * gambar master (pusat) agar kartu/galeri katalog tidak tampil kosong.
     *
     * @return Collection<int, Media>
     */
    public static function gallery(HasMedia $listing, ?HasMedia $master = null): Collection
    {
        $images = collect($listing->getMedia('images'));

        if ($images->isNotEmpty() || $master === null) {
            return $images;
        }

        return collect($master->getMedia('images'));
    }

    /**
     * Bentuk payload image_url + image.* agar FE bisa migrasi bertahap.
     *
     * @return array{url: ?string, thumb: ?string, preview: ?string, full: ?string}
     */
    public static function urls(?Media $media): array
    {
        if (! $media) {
            return ['url' => null, 'thumb' => null, 'preview' => null, 'full' => null];
        }

        return [
            'url' => $media->getUrl(),
            'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            'preview' => $media->hasGeneratedConversion('preview') ? $media->getUrl('preview') : null,
            'full' => $media->hasGeneratedConversion('full') ? $media->getUrl('full') : null,
        ];
    }
}
