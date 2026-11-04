<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Models\StoreProductListingWarehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreListingWarehouseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_store_listings_inherit_warehouse_assignments_from_master_variants(): void
    {
        $product = Product::factory()->create();
        $first = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Varian Pertama']);
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Varian Kedua']);
        $first->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $second->warehouseAssignments()->create(['warehouse_code' => 'WH-TASIK']);
        $listings = StoreProductListing::factory()->count(2)->create(['product_id' => $product->id]);
        $listings->load('warehouseAssignments');
        foreach ($listings as $listing) {
            $this->assertCount(2, $listing->warehouseAssignments);
            $this->assertEqualsCanonicalizing(['WH-BDG', 'WH-TASIK'], $listing->warehouseAssignments()->pluck('warehouse_code')->all());
            $this->assertEqualsCanonicalizing([$first->id, $second->id], $listing->warehouseAssignments()->pluck('product_variant_id')->all());
        }
        $this->assertDatabaseCount('store_product_listing_warehouses', 0);
    }

    public function test_master_changes_apply_to_existing_listings_and_legacy_store_overrides_are_ignored(): void
    {
        $variant = ProductVariant::factory()->create();
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        StoreProductListingWarehouse::query()->create(['store_product_listing_id' => $listing->id, 'warehouse_code' => 'WH-LEGACY']);
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $this->assertSame(['WH-BDG'], $listing->warehouseAssignments()->pluck('warehouse_code')->all());
        $variant->warehouseAssignments()->delete();
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-TASIK']);
        $this->assertSame(['WH-TASIK'], $listing->warehouseAssignments()->pluck('warehouse_code')->all());
        $variant->warehouseAssignments()->delete();
        $this->assertCount(0, $listing->warehouseAssignments()->get());
        $this->assertDatabaseCount('store_product_listing_warehouses', 1);
    }

    public function test_inactive_deleted_and_other_product_variants_are_not_inherited(): void
    {
        $variant = ProductVariant::factory()->create();
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        $variant->warehouseAssignments()->create(['warehouse_code' => 'WH-BDG']);
        $inactive = ProductVariant::factory()->create(['product_id' => $variant->product_id, 'name' => 'Varian Nonaktif', 'status' => 'inactive']);
        $inactive->warehouseAssignments()->create(['warehouse_code' => 'WH-INACTIVE']);
        $deleted = ProductVariant::factory()->create(['product_id' => $variant->product_id, 'name' => 'Varian Dihapus']);
        $deleted->warehouseAssignments()->create(['warehouse_code' => 'WH-DELETED']);
        $deleted->delete();
        ProductVariant::factory()->create()->warehouseAssignments()->create(['warehouse_code' => 'WH-OTHER']);
        $this->assertSame(['WH-BDG'], $listing->warehouseAssignments()->pluck('warehouse_code')->all());
    }
}
