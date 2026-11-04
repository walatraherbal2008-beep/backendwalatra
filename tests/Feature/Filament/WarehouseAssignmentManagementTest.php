<?php

namespace Tests\Feature\Filament;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Filament\Admin\Resources\StoreProductListings\Pages\CreateStoreProductListing;
use App\Filament\Admin\Resources\StoreProductListings\Pages\EditStoreProductListing;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductListing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        $provider = Mockery::mock(WarehouseDirectoryProvider::class);
        $provider->shouldReceive('list')->andReturn([
            ['code' => 'WH-BDG', 'realname' => 'Bandung', 'codename' => 'BANDUNG'],
            ['code' => 'WH-TASIK', 'realname' => 'Tasikmalaya', 'codename' => 'TASIK'],
        ]);
        $this->app->instance(WarehouseDirectoryProvider::class, $provider);
    }

    private function loginAs(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function manager(ProductVariant $variant)
    {
        return Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $variant->product,
            'pageClass' => EditProduct::class,
        ]);
    }

    public function test_table_displays_at_most_three_warehouse_names_and_leaves_unassigned_variants_blank(): void
    {
        $this->loginAs('super_admin');
        $variant = ProductVariant::factory()->create(['name' => 'Varian Bergudang']);
        $empty = ProductVariant::factory()->create(['product_id' => $variant->product_id, 'name' => 'Varian Tanpa Gudang']);
        foreach (['WH-BDG', 'WH-TASIK', 'WH-JKT', 'WH-SBY'] as $code) {
            $variant->warehouseAssignments()->create(['warehouse_code' => $code]);
        }
        $provider = Mockery::mock(WarehouseDirectoryProvider::class);
        $provider->shouldReceive('list')->once()->andReturn([
            ['code' => 'WH-BDG', 'realname' => 'Bandung'],
            ['code' => 'WH-TASIK', 'realname' => 'Tasikmalaya'],
            ['code' => 'WH-JKT', 'realname' => 'Jakarta'],
            ['code' => 'WH-SBY', 'realname' => 'Surabaya'],
        ]);
        $this->app->instance(WarehouseDirectoryProvider::class, $provider);

        $this->manager($variant)
            ->assertTableColumnStateSet('warehouse_names', ['Bandung', 'Tasikmalaya', 'Jakarta'], $variant)
            ->assertTableColumnStateSet('warehouse_names', null, $empty)
            ->assertDontSee('Surabaya')
            ->assertDontSee('Belum ditetapkan');
        $this->assertCount(4, $variant->warehouseAssignments()->get());
    }

    public function test_table_does_not_fall_back_to_codes_when_directory_is_unavailable(): void
    {
        $this->loginAs('super_admin');
        $variant = ProductVariant::factory()->create();
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $provider = Mockery::mock(WarehouseDirectoryProvider::class);
        $provider->shouldReceive('list')->once()->andThrow(new WarehouseDirectoryUnavailableException('API tidak tersedia.'));
        $this->app->instance(WarehouseDirectoryProvider::class, $provider);

        $this->manager($variant)->assertTableColumnStateSet('warehouse_names', null, $variant);
        $this->assertDatabaseHas('warehouse_product_variants', ['product_variant_id' => $variant->id, 'warehouse_code' => 'WH-BDG']);
    }

    public function test_superadmin_can_set_replace_and_clear_variant_warehouses_for_all_stores(): void
    {
        $this->loginAs('super_admin');
        $variant = ProductVariant::factory()->create();
        $listings = StoreProductListing::factory()->count(2)->create(['product_id' => $variant->product_id]);
        $this->manager($variant)->callTableAction('edit', $variant, data: ['name' => 'Varian Diperbarui', 'price' => 35000, 'warehouse_codes' => ['WH-BDG', 'WH-TASIK']])
            ->assertHasNoTableActionErrors();
        $this->assertDatabaseCount('warehouse_product_variants', 2);
        $this->assertSame('Varian Diperbarui', $variant->fresh()->name);
        $this->assertSame('35000.00', $variant->fresh()->price);
        foreach ($listings as $listing) {
            $this->assertCount(2, $listing->warehouseAssignments()->get());
        }
        $this->manager($variant)->mountTableAction('edit', $variant)
            ->assertTableActionDataSet(['warehouse_codes' => ['WH-BDG', 'WH-TASIK']]);
        $this->manager($variant)->callTableAction('edit', $variant, data: ['warehouse_codes' => ['WH-TASIK']])
            ->assertHasNoTableActionErrors();
        $this->assertSame(['WH-TASIK'], $listings->first()->warehouseAssignments()->pluck('warehouse_code')->all());
        $this->manager($variant)->callTableAction('edit', $variant, data: ['warehouse_codes' => []])
            ->assertHasNoTableActionErrors();
        $this->assertDatabaseCount('warehouse_product_variants', 0);
        $this->assertDatabaseCount('store_product_listing_warehouses', 0);
    }

    public function test_unknown_warehouse_is_rejected_without_replacing_existing_assignment(): void
    {
        $this->loginAs('super_admin');
        $variant = ProductVariant::factory()->create();
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $this->manager($variant)->callTableAction('edit', $variant, data: ['warehouse_codes' => ['WH-UNKNOWN']])
            ->assertHasTableActionErrors(['warehouse_codes.0']);
        $this->assertSame(['WH-BDG'], $variant->warehouseAssignments()->pluck('warehouse_code')->all());
    }

    public function test_variant_validation_failure_rolls_back_warehouse_changes(): void
    {
        $this->loginAs('super_admin');
        $variant = ProductVariant::factory()->create(['name' => 'Varian Asli']);
        ProductVariant::factory()->create(['product_id' => $variant->product_id, 'name' => 'Nama Sudah Dipakai']);
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);

        $this->manager($variant)->callTableAction('edit', $variant, data: [
            'name' => 'Nama Sudah Dipakai',
            'warehouse_codes' => ['WH-TASIK'],
        ])->assertHasTableActionErrors(['name']);

        $this->assertSame('Varian Asli', $variant->fresh()->name);
        $this->assertSame(['WH-BDG'], $variant->warehouseAssignments()->pluck('warehouse_code')->all());
    }

    public function test_directory_failure_does_not_clear_existing_assignment(): void
    {
        $this->loginAs('super_admin');
        $variant = ProductVariant::factory()->create();
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $provider = Mockery::mock(WarehouseDirectoryProvider::class);
        $provider->shouldReceive('list')->andThrow(new WarehouseDirectoryUnavailableException('API tidak tersedia.'));
        $this->app->instance(WarehouseDirectoryProvider::class, $provider);
        $this->manager($variant)->callTableAction('edit', $variant, data: ['name' => 'Perubahan Tidak Disimpan', 'warehouse_codes' => []]);
        $this->assertNotSame('Perubahan Tidak Disimpan', $variant->fresh()->name);
        $this->assertSame(['WH-BDG'], $variant->warehouseAssignments()->pluck('warehouse_code')->all());
    }

    public function test_store_admin_has_no_warehouse_field_and_cannot_open_master_product_editor(): void
    {
        $admin = $this->loginAs('store_admin');
        $store = Store::factory()->create(['owner_user_id' => $admin->id]);
        $variant = ProductVariant::factory()->create();
        $listing = StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $variant->product_id, 'status' => 'draft']);
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $this->assertFalse(ProductResource::canAccess());
        $this->get('/superadmin/products/'.$variant->product_id.'/edit')->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CreateStoreProductListing::class)->assertFormFieldDoesNotExist('warehouse_codes');
        Livewire::test(EditStoreProductListing::class, ['record' => $listing->id])
            ->assertFormFieldDoesNotExist('warehouse_codes')
            ->set('data.warehouse_codes', ['WH-TASIK'])
            ->fillForm(['description' => 'Penawaran toko diperbarui.'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['WH-BDG'], $variant->warehouseAssignments()->pluck('warehouse_code')->all());
        $this->assertDatabaseCount('store_product_listing_warehouses', 0);
    }

    public function test_store_admin_is_not_authorized_for_warehouse_action(): void
    {
        $this->loginAs('store_admin');
        $variant = ProductVariant::factory()->create();
        $this->manager($variant)->assertTableActionHidden('edit', $variant);
        $this->assertDatabaseCount('warehouse_product_variants', 0);
    }
}
