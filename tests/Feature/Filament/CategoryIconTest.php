<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    public function test_admin_can_create_category_with_icon_and_see_it_in_table_and_api(): void
    {
        Livewire::test(CreateCategory::class)
            ->fillForm([
                'name' => 'Pencernaan',
                'icon' => [UploadedFile::fake()->image('icon.png', 512, 512)],
            ])->call('create')->assertHasNoFormErrors();

        $category = Category::query()->sole();
        $media = $category->getFirstMedia('icon');
        $this->assertNotNull($media);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        Livewire::test(ListCategories::class)
            ->assertTableColumnStateSet('icon_url', $media->getUrl(), $category);
        $this->getJson('/api/v1/catalog/categories')
            ->assertOk()->assertJsonPath('data.0.icon_url', $media->getUrl());
    }

    public function test_admin_can_replace_then_remove_existing_icon(): void
    {
        $category = Category::factory()->create();
        $original = $category->addMedia(UploadedFile::fake()->image('old.png'))->toMediaCollection('icon');
        $oldPath = $original->getPathRelativeToRoot();

        Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['icon' => [UploadedFile::fake()->image('new.png')]])
            ->call('save')->assertHasNoFormErrors();

        $category->refresh();
        $this->assertCount(1, $category->getMedia('icon'));
        $replacement = $category->getFirstMedia('icon');
        $this->assertNotSame($original->id, $replacement->id);
        Storage::disk('public')->assertExists($replacement->getPathRelativeToRoot());
        Storage::disk('public')->assertMissing($oldPath);

        Livewire::test(EditCategory::class, ['record' => $category->id])
            ->fillForm(['icon' => []])->call('save')->assertHasNoFormErrors();
        $this->assertCount(0, $category->fresh()->getMedia('icon'));
        $this->getJson('/api/v1/catalog/categories/'.$category->slug)
            ->assertOk()->assertJsonPath('data.icon_url', null);
    }

    public function test_icon_is_optional_and_invalid_uploads_are_rejected(): void
    {
        Livewire::test(CreateCategory::class)->fillForm(['name' => 'Tanpa ikon'])
            ->call('create')->assertHasNoFormErrors();

        foreach ([
            UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('large.png')->size(2049),
        ] as $file) {
            Livewire::test(CreateCategory::class)
                ->fillForm(['name' => 'Tidak valid', 'icon' => [$file]])
                ->call('create')->assertHasFormErrors(['icon']);
        }
        $this->assertDatabaseCount('categories', 1);
    }
}
