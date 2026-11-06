<?php

namespace Tests\Feature\Filament;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VariantPhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
        $provider = Mockery::mock(WarehouseDirectoryProvider::class);
        $provider->shouldReceive('list')->andReturn([['code' => 'WH-BDG', 'realname' => 'Bandung', 'codename' => 'BANDUNG']]);
        $this->app->instance(WarehouseDirectoryProvider::class, $provider);
    }

    private function manager(ProductVariant $variant)
    {
        return Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->product, 'pageClass' => EditProduct::class,
        ]);
    }

    public function test_superadmin_can_create_variant_with_photo_and_preview_in_table(): void
    {
        $existing = ProductVariant::factory()->create();
        $this->manager($existing)->callTableAction('create', data: [
            'name' => 'Botol 100 ml', 'price' => 35000, 'weight_grams' => 100, 'status' => 'active',
            'image' => [UploadedFile::fake()->image('variant.png', 256, 512)],
        ])->assertHasNoTableActionErrors();
        $variant = ProductVariant::where('name', 'Botol 100 ml')->sole();
        $photo = $variant->getFirstMedia('image');
        $this->assertNotNull($photo);
        Storage::disk('public')->assertExists($photo->getPathRelativeToRoot());
        $dimensions = getimagesize($photo->getPath());
        $this->assertSame([256, 512], [$dimensions[0], $dimensions[1]]);
        $this->manager($variant)->assertTableColumnStateSet('image_url', $photo->getUrl(), $variant)
            ->assertTableColumnStateSet('image_url', null, $existing);
    }

    public function test_edit_preserves_existing_photo_and_warehouse_then_can_replace_and_remove_photo(): void
    {
        $variant = ProductVariant::factory()->create();
        $sku = $variant->sku;
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $original = $variant->addMedia(UploadedFile::fake()->image('old.png'))->toMediaCollection('image');
        $path = $original->getPathRelativeToRoot();
        $this->manager($variant)->callTableAction('edit', $variant, data: ['price' => 50000])
            ->assertHasNoTableActionErrors();
        $this->assertSame($original->id, $variant->fresh()->getFirstMedia('image')->id);
        $this->assertSame($sku, $variant->fresh()->sku);
        $this->assertSame(['WH-BDG'], $variant->warehouseAssignments()->pluck('warehouse_code')->all());

        $this->manager($variant->fresh())->callTableAction('edit', $variant, data: [
            'image' => [UploadedFile::fake()->image('new.webp')],
        ])->assertHasNoTableActionErrors();
        $photo = $variant->fresh()->getFirstMedia('image');
        $this->assertNotSame($original->id, $photo->id);
        $this->assertCount(1, $variant->fresh()->getMedia('image'));
        Storage::disk('public')->assertMissing($path);
        $this->manager($variant->fresh())->callTableAction('edit', $variant, data: ['image' => []])
            ->assertHasNoTableActionErrors();
        $this->assertCount(0, $variant->fresh()->getMedia('image'));
        Storage::disk('public')->assertMissing($photo->getPathRelativeToRoot());
    }

    public function test_warehouse_failure_preserves_existing_photo_and_variant_data(): void
    {
        $variant = ProductVariant::factory()->create(['price' => 20000]);
        $photo = $variant->addMedia(UploadedFile::fake()->image('old.png'))->toMediaCollection('image');
        $provider = Mockery::mock(WarehouseDirectoryProvider::class);
        $provider->shouldReceive('list')->andThrow(new WarehouseDirectoryUnavailableException('Tidak tersedia'));
        $this->app->instance(WarehouseDirectoryProvider::class, $provider);
        $this->manager($variant)->callTableAction('edit', $variant, data: [
            'price' => 50000, 'image' => [UploadedFile::fake()->image('new.png')],
        ]);
        $this->assertSame('20000.00', $variant->fresh()->price);
        $this->assertSame($photo->id, $variant->fresh()->getFirstMedia('image')->id);
        Storage::disk('public')->assertExists($photo->getPathRelativeToRoot());
    }

    public function test_photo_is_optional_and_invalid_type_or_oversized_file_is_rejected(): void
    {
        $existing = ProductVariant::factory()->create();
        $data = ['name' => 'Tanpa foto', 'price' => 10000, 'weight_grams' => 100, 'status' => 'active'];
        $this->manager($existing)->callTableAction('create', data: $data)->assertHasNoTableActionErrors();
        foreach ([
            UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('big.png')->size(5121),
        ] as $file) {
            $this->manager($existing)->callTableAction('create', data: [
                ...$data, 'name' => 'Ditolak', 'image' => [$file],
            ])->assertHasTableActionErrors(['image']);
        }
        $this->assertDatabaseCount('product_variants', 2);
    }
}
