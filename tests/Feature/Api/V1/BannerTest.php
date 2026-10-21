<?php

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_banner_endpoint_returns_only_active_banners_within_schedule_in_order(): void
    {
        Storage::fake('public');
        Queue::fake();

        $visible = Banner::query()->create([
            'name' => 'Banner tampil',
            'cta_url' => 'https://example.com/promo',
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'sort_order' => 1,
        ]);
        $visible->addMedia(UploadedFile::fake()->image('banner.png', 1200, 420))
            ->toMediaCollection('image');

        $visibleLatest = Banner::query()->create([
            'name' => 'Banner terbaru',
            'cta_url' => 'https://example.com/latest',
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'sort_order' => 1,
        ]);
        $visibleLatest->addMedia(UploadedFile::fake()->image('banner-latest.png', 1200, 420))
            ->toMediaCollection('image');

        Banner::query()->create([
            'name' => 'Aktif tanpa gambar',
            'cta_url' => 'https://example.com/no-image',
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'sort_order' => 0,
        ]);

        Banner::query()->create([
            'name' => 'Belum mulai',
            'cta_url' => 'https://example.com/future',
            'status' => 'active',
            'starts_at' => now()->addHour(),
            'sort_order' => 2,
        ]);
        Banner::query()->create([
            'name' => 'Sudah berakhir',
            'cta_url' => 'https://example.com/expired',
            'status' => 'active',
            'ends_at' => now()->subHour(),
            'sort_order' => 3,
        ]);
        Banner::query()->create([
            'name' => 'Nonaktif',
            'cta_url' => 'https://example.com/inactive',
            'status' => 'inactive',
            'sort_order' => 0,
        ]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $visibleLatest->id)
            ->assertJsonPath('data.0.name', 'Banner terbaru')
            ->assertJsonPath('data.0.cta_url', 'https://example.com/latest')
            ->assertJsonPath('data.0.sort_order', 1)
            ->assertJsonPath('data.0.image_url', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('data.1.id', $visible->id)
            ->assertJsonPath('data.1.name', 'Banner tampil')
            ->assertJsonPath('data.1.cta_url', 'https://example.com/promo')
            ->assertJsonPath('data.1.sort_order', 1)
            ->assertJsonMissing(['name' => 'Aktif tanpa gambar'])
            ->assertJsonMissing(['name' => 'Belum mulai'])
            ->assertJsonMissing(['name' => 'Sudah berakhir'])
            ->assertJsonMissing(['name' => 'Nonaktif']);
    }

    public function test_public_banner_endpoint_returns_empty_data_when_no_banner_is_visible(): void
    {
        Banner::query()->create([
            'name' => 'Banner draft',
            'cta_url' => 'https://example.com/draft',
            'status' => 'draft',
        ]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Daftar banner berhasil diambil.')
            ->assertJsonCount(0, 'data');
    }

    public function test_banner_rejects_unsafe_cta_and_invalid_schedule(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        Banner::query()->create([
            'name' => 'Banner tidak valid',
            'cta_url' => 'javascript:alert(1)',
            'status' => 'draft',
            'starts_at' => now()->addDay(),
            'ends_at' => now(),
        ]);
    }
}
