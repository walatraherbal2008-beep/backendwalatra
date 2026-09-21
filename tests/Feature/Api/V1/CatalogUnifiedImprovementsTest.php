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

class CatalogUnifiedImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_catalog_supports_relevance_sort_with_exact_match_first(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $exact = Product::factory()->create(['name' => 'Madu Lambung', 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $exact->id, 'status' => 'active']);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $exact->id, 'status' => 'active']);

        $other = Product::factory()->create(['name' => 'Teh Herbal Madu Lambung Campur', 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $other->id, 'status' => 'active']);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $other->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?q=Madu%20Lambung&sort=relevance')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Madu Lambung');
    }

    public function test_unified_catalog_defaults_to_relevance_when_searching(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['name' => 'Propolis Unik Sekali', 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?q=Unik%20Sekali')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Propolis Unik Sekali');
    }

    public function test_unified_catalog_search_finds_sku_and_store_description(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['name' => 'Produk SKU Test', 'status' => 'active']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-UNIK-12345',
            'status' => 'active',
        ]);
        StoreProductListing::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'description' => 'Khusus untuk asam lambung kronis',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/catalog?q=SKU-UNIK-12345')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Produk SKU Test');

        $this->getJson('/api/v1/catalog?q=lambung%20kronis')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Produk SKU Test');
    }

    public function test_unified_catalog_search_finds_bundle_component_name(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['name' => 'Jahe Merah Premium', 'status' => 'active']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
        $bundle = ProductBundle::factory()->create(['name' => 'Paket Sehat Keluarga', 'status' => 'active']);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
        StoreBundleListing::factory()->create(['store_id' => $store->id, 'product_bundle_id' => $bundle->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?q=Jahe%20Merah&type=bundle')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Paket Sehat Keluarga');
    }

    public function test_unified_catalog_token_order_does_not_matter(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['name' => 'Madu Asli Sumbawa', 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
        StoreProductListing::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'description' => 'Untuk kesehatan lambung kronis',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/catalog?q=kronis%20lambung')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Madu Asli Sumbawa');
    }

    public function test_unified_catalog_search_finds_variant_name_and_category(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $category = Category::factory()->create(['name' => 'Pencernaan', 'slug' => 'pencernaan-test', 'status' => 'active']);
        $product = Product::factory()->create(['name' => 'Herbal Lambung X', 'status' => 'active']);
        $product->categories()->attach($category);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => 'Kemasan 100ml',
            'status' => 'active',
        ]);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?q=100ml')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Herbal Lambung X');

        $this->getJson('/api/v1/catalog?q=pencernaan')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Herbal Lambung X');
    }

    public function test_unified_catalog_returns_matched_in_hint(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['name' => 'Madu Hutan Kalimantan', 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?q=Hutan%20Kalimantan')
            ->assertOk()
            ->assertJsonPath('data.0.matched_in', ['name']);
    }

    public function test_unified_catalog_pagination_uses_explicit_page(): void
    {
        $store = Store::factory()->create(['status' => 'active']);

        foreach (['Produk A1', 'Produk A2', 'Produk A3'] as $name) {
            $product = Product::factory()->create(['name' => $name, 'status' => 'active']);
            ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
            StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);
        }

        $page1 = $this->getJson('/api/v1/catalog?type=product&per_page=2&page=1&sort=name_asc')->assertOk();
        $page2 = $this->getJson('/api/v1/catalog?type=product&per_page=2&page=2&sort=name_asc')->assertOk();

        $page2->assertJsonPath('meta.current_page', 2);
        $this->assertNotSame($page1->json('data.0.listing_id'), $page2->json('data.0.listing_id'));
    }

    public function test_unified_catalog_avoids_n_plus_one_queries(): void
    {
        $store = Store::factory()->create(['status' => 'active']);

        for ($i = 1; $i <= 10; $i++) {
            $product = Product::factory()->create(['name' => "Produk NPlus {$i}", 'status' => 'active']);
            ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
            StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);
        }

        DB::enableQueryLog();

        $this->getJson('/api/v1/catalog?type=product&per_page=10')->assertOk();

        // 1 count + 1 data + 2 preload (listing + media) + relasi resource.
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
    }
}
