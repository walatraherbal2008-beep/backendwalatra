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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function unavailableProductRelations(): array
    {
        return [['product'], ['variant'], ['store'], ['deleted_product']];
    }

    #[DataProvider('unavailableProductRelations')]
    public function test_product_activation_rejects_stale_preloaded_relations(string $changed): void
    {
        [$product, $variant] = $this->productWithActiveVariant();
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id, 'status' => 'draft']);
        $listing->load('product.variants', 'store');

        match ($changed) {
            'product' => $product->fresh()->update(['status' => 'inactive']),
            'variant' => $variant->fresh()->update(['status' => 'inactive']),
            'store' => $listing->store->fresh()->update(['status' => 'inactive']),
            'deleted_product' => $product->fresh()->delete(),
        };

        $this->expectException(ValidationException::class);
        $listing->update(['status' => 'active']);
    }

    public static function unavailableBundleRelations(): array
    {
        return [['bundle'], ['variant'], ['product'], ['store'], ['deleted_item']];
    }

    #[DataProvider('unavailableBundleRelations')]
    public function test_bundle_activation_rejects_stale_preloaded_relations(string $changed): void
    {
        [$product, $variant] = $this->productWithActiveVariant();
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        $item = ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id, 'status' => 'draft']);
        $listing->load('bundle.items.variant.product', 'store');

        match ($changed) {
            'bundle' => $bundle->fresh()->update(['status' => 'inactive']),
            'variant' => $variant->fresh()->update(['status' => 'inactive']),
            'product' => $product->fresh()->update(['status' => 'inactive']),
            'store' => $listing->store->fresh()->update(['status' => 'inactive']),
            'deleted_item' => $item->fresh()->delete(),
        };

        $this->expectException(ValidationException::class);
        $listing->update(['status' => 'active']);
    }

    public function test_product_listing_activation_checks_database_with_two_existence_queries(): void
    {
        [$product] = $this->productWithActiveVariant();
        $store = Store::factory()->create(['status' => 'active']);
        $listing = StoreProductListing::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'status' => 'draft',
        ]);

        $product->load('variants');
        $listing->setRelation('product', $product);
        $listing->setRelation('store', $store);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $listing->update(['status' => 'active']);

        $relationSelects = collect(DB::getQueryLog())->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with(ltrim($sql), 'select')
                && (str_contains($sql, 'products')
                    || str_contains($sql, 'product_variants')
                    || str_contains($sql, 'stores'));
        });

        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(2, $relationSelects);
        $this->assertTrue($relationSelects->every(fn (array $query): bool => str_contains(strtolower($query['query']), 'exists')));
        $this->assertSame('active', $listing->fresh()->status);
    }

    public function test_bundle_listing_activation_checks_database_with_two_existence_queries(): void
    {
        [, $variant] = $this->productWithActiveVariant();
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
        ]);
        $store = Store::factory()->create(['status' => 'active']);
        $listing = StoreBundleListing::factory()->create([
            'product_bundle_id' => $bundle->id,
            'store_id' => $store->id,
            'status' => 'draft',
        ]);

        $bundle->load('items.variant.product');
        $listing->setRelation('bundle', $bundle);
        $listing->setRelation('store', $store);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $listing->update(['status' => 'active']);

        $relationSelects = collect(DB::getQueryLog())->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with(ltrim($sql), 'select')
                && (str_contains($sql, 'product_bundles')
                    || str_contains($sql, 'product_bundle_items')
                    || str_contains($sql, 'product_variants')
                    || str_contains($sql, 'products')
                    || str_contains($sql, 'stores'));
        });

        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(2, $relationSelects);
        $this->assertTrue($relationSelects->every(fn (array $query): bool => str_contains(strtolower($query['query']), 'exists')));
        $this->assertSame('active', $listing->fresh()->status);
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
