<?php

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BannerPositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
    }

    private function banner(string $position, int $order = 1, array $attributes = []): Banner
    {
        $banner = Banner::create([
            'name' => $position.' '.$order, 'position' => $position,
            'cta_url' => 'https://example.com/promo', 'status' => 'active', 'sort_order' => $order,
            ...$attributes,
        ]);
        $banner->addMedia(UploadedFile::fake()->image('banner.png'))->toMediaCollection('image');

        return $banner;
    }

    public function test_public_api_exposes_all_areas_and_filters_with_order_and_visibility_preserved(): void
    {
        $later = $this->banner('main', 20);
        $first = $this->banner('main', 10);
        $top = $this->banner('secondary_top');
        $bottom = $this->banner('secondary_bottom');
        $this->banner('main', 1, ['ends_at' => now()->subDay()]);
        $this->banner('secondary_top', 1, ['status' => 'draft']);
        $this->banner('secondary_bottom', 1, ['starts_at' => now()->addDay()]);

        $this->getJson('/api/v1/banners')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/banners?position=main')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $later->id)
            ->assertJsonPath('data.0.position', 'main');
        foreach (['secondary_top' => $top, 'secondary_bottom' => $bottom] as $position => $banner) {
            $this->getJson('/api/v1/banners?position='.$position)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $banner->id)->assertJsonPath('data.0.position', $position);
        }
    }

    public function test_invalid_position_filter_returns_validation_error_and_empty_area_returns_empty_array(): void
    {
        foreach (['invalid', 'MAIN', '123', 'position_array'] as $invalid) {
            $query = $invalid === 'position_array' ? 'position[]=main' : 'position='.$invalid;
            $this->getJson('/api/v1/banners?'.$query)->assertUnprocessable()->assertJsonValidationErrors('position');
        }
        $this->getJson('/api/v1/banners?position=secondary_bottom')->assertOk()->assertJsonPath('data', []);
    }

    public function test_invalid_position_cannot_be_saved_through_model(): void
    {
        $this->expectException(ValidationException::class);
        Banner::create(['name' => 'Invalid', 'cta_url' => 'https://example.com', 'position' => 'invalid']);
    }

    public function test_migration_preserves_existing_banners_with_main_as_default(): void
    {
        $migration = require database_path('migrations/2026_11_06_010000_add_position_to_banners_table.php');
        $migration->down();
        $id = DB::table('banners')->insertGetId(['name' => 'Banner lama', 'cta_url' => 'https://example.com/old']);
        $migration->up();
        $banner = Banner::findOrFail($id);
        $this->assertSame('main', $banner->position);
        $this->assertSame('https://example.com/old', $banner->cta_url);
    }
}
