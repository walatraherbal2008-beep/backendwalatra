<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_search_is_consistent_with_unified_catalog(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $category = Category::factory()->create(['name' => 'Pencernaan X', 'slug' => 'pencernaan-x-test', 'status' => 'active']);
        $product = Product::factory()->create(['name' => 'Herbal Lambung 100ml', 'status' => 'active']);
        $product->categories()->attach($category);
        ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Kemasan 100ml', 'status' => 'active']);
        StoreProductListing::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'description' => 'Untuk lambung kronis',
            'status' => 'active',
        ]);

        // Nama varian.
        $this->getJson('/api/v1/catalog/products?q=100ml')
            ->assertOk()
            ->assertJsonPath('data.0.product.name', 'Herbal Lambung 100ml');

        // Kategori.
        $this->getJson('/api/v1/catalog/products?q=pencernaan%20x')
            ->assertOk()
            ->assertJsonPath('data.0.product.name', 'Herbal Lambung 100ml');

        // Token-AND beda urutan + lintas field (deskripsi + nama).
        $this->getJson('/api/v1/catalog/products?q=kronis%20herbal')
            ->assertOk()
            ->assertJsonPath('data.0.product.name', 'Herbal Lambung 100ml');
    }

    public function test_category_product_count_ignores_products_without_active_variant(): void
    {
        $category = Category::factory()->create(['slug' => 'count-test', 'status' => 'active']);
        $emptyProduct = Product::factory()->create(['status' => 'active']);
        $emptyProduct->categories()->attach($category);
        ProductVariant::factory()->create(['product_id' => $emptyProduct->id, 'status' => 'inactive']);
        StoreProductListing::factory()->create(['product_id' => $emptyProduct->id, 'status' => 'draft']);

        $this->getJson('/api/v1/catalog/categories/count-test')
            ->assertOk()
            ->assertJsonPath('data.product_count', 0);
    }

    public function test_catalog_endpoints_stay_within_query_budget(): void
    {
        $store = Store::factory()->create(['status' => 'active']);

        for ($i = 1; $i <= 5; $i++) {
            $product = Product::factory()->create(['name' => "Produk Budget {$i}", 'status' => 'active']);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
            StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);

            if ($i === 1) {
                $bundle = ProductBundle::factory()->create(['name' => 'Paket Budget', 'status' => 'active']);
                ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
                StoreBundleListing::factory()->create(['store_id' => $store->id, 'product_bundle_id' => $bundle->id, 'status' => 'active']);
            }
        }

        $product = Product::query()->firstOrFail();
        $bundle = ProductBundle::query()->firstOrFail();

        // List produk (15 query: data + count + eager relasi + filter exists).
        DB::enableQueryLog();
        $this->getJson('/api/v1/catalog/products?per_page=5')->assertOk();
        $this->assertLessThanOrEqual(15, count(DB::getQueryLog()), 'products list melebihi budget query');

        // Detail produk.
        DB::flushQueryLog();
        $this->getJson('/api/v1/catalog/products/'.$product->slug)->assertOk();
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()), 'products detail melebihi budget query');

        // List bundle.
        DB::flushQueryLog();
        $this->getJson('/api/v1/catalog/bundles?per_page=5')->assertOk();
        $this->assertLessThanOrEqual(15, count(DB::getQueryLog()), 'bundles list melebihi budget query');

        // Detail bundle.
        DB::flushQueryLog();
        $this->getJson('/api/v1/catalog/bundles/'.$bundle->slug)->assertOk();
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()), 'bundles detail melebihi budget query');
    }
}
