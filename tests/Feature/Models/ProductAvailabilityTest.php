<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactivating_product_inactivates_product_and_bundle_listings(): void
    {
        [$product, $variant] = $this->productWithActiveVariant();
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
        ]);
        $store = Store::factory()->create();
        $productListing = StoreProductListing::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'status' => 'active',
        ]);
        $bundleListing = StoreBundleListing::factory()->create([
            'product_bundle_id' => $bundle->id,
            'store_id' => $store->id,
            'status' => 'active',
        ]);

        $product->update(['status' => 'inactive']);

        $this->assertSame('inactive', $productListing->fresh()->status);
        $this->assertNull($productListing->fresh()->published_at);
        $this->assertSame('inactive', $bundleListing->fresh()->status);
        $this->assertNull($bundleListing->fresh()->published_at);
    }

    public function test_soft_deleting_product_inactivates_listings_without_deleting_history(): void
    {
        [$product] = $this->productWithActiveVariant();
        $store = Store::factory()->create();
        $listing = StoreProductListing::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'status' => 'active',
        ]);

        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('store_product_listings', [
            'id' => $listing->id,
            'status' => 'inactive',
            'published_at' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_deactivating_last_active_variant_inactivates_product_and_listing(): void
    {
        [$product, $variant] = $this->productWithActiveVariant();
        $store = Store::factory()->create();
        $listing = StoreProductListing::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'status' => 'active',
        ]);

        $variant->update(['status' => 'inactive']);

        $this->assertSame('inactive', $product->fresh()->status);
        $this->assertSame('inactive', $listing->fresh()->status);
    }

    public function test_restoring_product_does_not_reactivate_listing(): void
    {
        [$product] = $this->productWithActiveVariant();
        $store = Store::factory()->create();
        $listing = StoreProductListing::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'status' => 'active',
        ]);

        $product->delete();
        $product->restore();

        $this->assertSame('inactive', $listing->fresh()->status);
    }

    public function test_inactivating_bundle_variant_inactivates_bundle_listing_even_when_product_has_another_active_variant(): void
    {
        $product = Product::factory()->create(['status' => 'active']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => '50 Kapsul',
            'status' => 'active',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => '100 Kapsul',
            'status' => 'active',
        ]);
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
        ]);
        $listing = StoreBundleListing::factory()->create([
            'product_bundle_id' => $bundle->id,
            'status' => 'active',
        ]);

        $variant->update(['status' => 'inactive']);

        $this->assertSame('active', $product->fresh()->status);
        $this->assertSame('inactive', $listing->fresh()->status);
    }

    public function test_variant_rejects_invalid_price_and_weight(): void
    {
        $product = Product::factory()->create();

        $this->expectException(ValidationException::class);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 0,
        ]);
    }

    public function test_variant_name_must_be_unique_within_the_same_product(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => '50 Kapsul',
        ]);

        $this->expectException(ValidationException::class);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => '50 kapsul',
        ]);
    }

    public function test_variant_sku_cannot_be_changed_after_creation(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->expectException(ValidationException::class);
        $variant->update(['sku' => 'SKU-WAL-CHANGED']);
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function productWithActiveVariant(): array
    {
        $product = Product::factory()->create(['status' => 'active']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
        ]);

        return [$product, $variant];
    }
}
