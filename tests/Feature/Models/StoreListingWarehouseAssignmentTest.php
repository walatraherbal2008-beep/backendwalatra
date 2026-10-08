<?php

namespace Tests\Feature\Models;

use App\Contracts\WarehouseDirectoryProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Services\Warehouse\StoreListingWarehouseAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreListingWarehouseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(WarehouseDirectoryProvider::class, function (): object {
            return new class implements WarehouseDirectoryProvider
            {
                public function list(): array
                {
                    return [
                        ['code' => 'WH-BDG', 'initial' => 'BD', 'codename' => 'BANDUNG', 'realname' => 'Bandung', 'area_group' => 'pulaujawa'],
                        ['code' => 'WH-TASIK', 'initial' => 'TM', 'codename' => 'TASIK', 'realname' => 'Tasikmalaya', 'area_group' => 'pulaujawa'],
                    ];
                }

                public function listByGroup(string $areaGroup): array
                {
                    return array_values(array_filter(
                        $this->list(),
                        fn (array $warehouse): bool => $warehouse['area_group'] === $areaGroup,
                    ));
                }
            };
        });
    }

    public function test_options_are_the_intersection_of_all_active_product_variants(): void
    {
        $product = Product::factory()->create();
        $firstVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Paket Pertama']);
        $secondVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Paket Kedua']);

        $firstVariant->warehouseAssignments()->createMany([
            ['warehouse_code' => 'WH-BDG'],
            ['warehouse_code' => 'WH-TASIK'],
        ]);
        $secondVariant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);

        $options = app(StoreListingWarehouseAssignmentService::class)->availableOptions($product->id);

        $this->assertSame(['WH-BDG' => 'Bandung (BANDUNG)'], $options);
    }

    public function test_selected_warehouses_are_saved_per_store_listing(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        app(StoreListingWarehouseAssignmentService::class)->sync($listing, ['WH-BDG', 'WH-BDG']);

        $this->assertDatabaseCount('store_product_listing_warehouses', 1);
        $this->assertDatabaseHas('store_product_listing_warehouses', [
            'store_product_listing_id' => $listing->id,
            'warehouse_code' => 'WH-BDG',
        ]);
    }

    public function test_warehouse_not_available_for_every_variant_is_rejected(): void
    {
        $product = Product::factory()->create();
        $firstVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Paket Pertama']);
        $secondVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Paket Kedua']);
        $firstVariant->warehouseAssignments()->createMany([
            ['warehouse_code' => 'WH-BDG'],
            ['warehouse_code' => 'WH-TASIK'],
        ]);
        $secondVariant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->expectException(ValidationException::class);

        app(StoreListingWarehouseAssignmentService::class)->sync($listing, ['WH-TASIK']);
    }
}
