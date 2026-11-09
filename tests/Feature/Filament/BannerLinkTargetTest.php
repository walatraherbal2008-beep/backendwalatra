<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BannerLinkTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    public function test_create_defaults_to_same_tab_and_can_choose_new_tab(): void
    {
        foreach ([false, true] as $newTab) {
            $page = Livewire::test(CreateBanner::class)->assertFormSet(['open_in_new_tab' => false]);
            $data = [
                'name' => $newTab ? 'Tab baru' : 'Tab yang sama',
                'cta_url' => 'https://example.com/promo',
                'image' => [UploadedFile::fake()->image('banner.png', 1536, 625)],
            ];
            if ($newTab) {
                $data['open_in_new_tab'] = true;
            }
            $page->fillForm($data)->call('create')->assertHasNoFormErrors();
            $banner = Banner::where('name', $data['name'])->sole();
            $this->assertSame($newTab, $banner->open_in_new_tab);
            $this->assertCount(1, $banner->getMedia('image'));
        }
    }

    public function test_edit_can_switch_both_ways_without_changing_url_or_image(): void
    {
        $banner = Banner::create(['name' => 'Promo', 'cta_url' => 'https://example.com/promo']);
        $media = $banner->addMedia(UploadedFile::fake()->image('banner.png'))->toMediaCollection('image');
        foreach ([true, false] as $newTab) {
            Livewire::test(EditBanner::class, ['record' => $banner->id])
                ->fillForm(['open_in_new_tab' => $newTab])->call('save')->assertHasNoFormErrors();
            $banner->refresh();
            $this->assertSame($newTab, $banner->open_in_new_tab);
            $this->assertSame('https://example.com/promo', $banner->cta_url);
            $this->assertSame($media->id, $banner->getFirstMedia('image')->id);
        }
    }

    public function test_invalid_target_value_is_rejected(): void
    {
        $banner = Banner::create(['name' => 'Promo', 'cta_url' => 'https://example.com/promo']);
        Livewire::test(EditBanner::class, ['record' => $banner->id])
            ->fillForm(['open_in_new_tab' => 2])->call('save')->assertHasFormErrors(['open_in_new_tab']);
        $this->assertFalse($banner->fresh()->open_in_new_tab);
    }

    public function test_superadmin_can_create_banners_in_each_area_and_default_is_main(): void
    {
        foreach (array_keys(Banner::POSITIONS) as $position) {
            Livewire::test(CreateBanner::class)->assertFormSet(['position' => 'main'])
                ->fillForm([
                    'name' => $position, 'position' => $position,
                    'cta_url' => 'https://example.com/promo',
                    'image' => [UploadedFile::fake()->image('banner.png')],
                ])->call('create')->assertHasNoFormErrors();
            $this->assertSame($position, Banner::where('name', $position)->sole()->position);
        }
    }

    public function test_superadmin_can_change_area_and_filter_table_without_losing_banner_settings(): void
    {
        $banner = Banner::create(['name' => 'Promo', 'cta_url' => 'https://example.com/promo', 'open_in_new_tab' => true]);
        $image = $banner->addMedia(UploadedFile::fake()->image('banner.png'))->toMediaCollection('image');
        foreach (array_keys(Banner::POSITIONS) as $position) {
            Livewire::test(EditBanner::class, ['record' => $banner->id])->fillForm(['position' => $position])
                ->call('save')->assertHasNoFormErrors();
            $this->assertSame($position, $banner->fresh()->position);
        }
        $banner->refresh();
        $this->assertTrue($banner->open_in_new_tab);
        $this->assertSame('https://example.com/promo', $banner->cta_url);
        $this->assertSame($image->id, $banner->getFirstMedia('image')->id);
        $main = Banner::create(['name' => 'Utama', 'cta_url' => 'https://example.com']);
        Livewire::test(ListBanners::class)->filterTable('position', 'secondary_bottom')
            ->assertCanSeeTableRecords([$banner])->assertCanNotSeeTableRecords([$main]);
        Livewire::test(EditBanner::class, ['record' => $banner->id])->fillForm(['position' => 'invalid'])
            ->call('save')->assertHasFormErrors(['position']);
        $this->assertSame('secondary_bottom', $banner->fresh()->position);
    }

    public function test_migration_defaults_existing_banners_to_same_tab_and_can_roll_back(): void
    {
        $migration = require database_path('migrations/2026_11_06_000000_add_open_in_new_tab_to_banners_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('banners', 'open_in_new_tab'));
        $id = DB::table('banners')->insertGetId(['name' => 'Banner lama', 'cta_url' => 'https://example.com/old']);
        $migration->up();
        $oldBanner = Banner::findOrFail($id);
        $this->assertFalse($oldBanner->open_in_new_tab);
        $this->assertSame('https://example.com/old', $oldBanner->cta_url);
    }
}
