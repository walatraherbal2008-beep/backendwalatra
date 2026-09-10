<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_only_returns_active_product_listings_from_active_stores(): void
    {
        $activeProduct = Product::factory()->create(['name' => 'Keloreena Herbal']);
        ProductVariant::factory()->create([
            'product_id' => $activeProduct->id,
            'price' => 93000,
        ]);
        $activeStore = Store::factory()->create(['name' => 'Toko Sehat Aktif']);
        StoreProductListing::factory()->create([
            'product_id' => $activeProduct->id,
            'store_id' => $activeStore->id,
            'description' => 'Deskripsi produk dari toko.',
        ]);

        $inactiveStore = Store::factory()->create(['status' => 'inactive']);
        StoreProductListing::factory()->create([
            'product_id' => $activeProduct->id,
            'store_id' => $inactiveStore->id,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/v1/catalog/products?q=Keloreena');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Keloreena Herbal')
            ->assertJsonPath('data.0.store.name', 'Toko Sehat Aktif')
            ->assertJsonPath('data.0.variants.0.price', '93000.00');
    }

    public function test_catalog_product_detail_returns_active_store_listings(): void
    {
        $product = Product::factory()->create(['name' => 'Propolis SM']);
        ProductVariant::factory()->create(['product_id' => $product->id]);
        StoreProductListing::factory()->create(['product_id' => $product->id]);

        $response = $this->getJson('/api/v1/catalog/products/'.$product->slug);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Propolis SM')
            ->assertJsonCount(1, 'data.stores')
            ->assertJsonPath('data.stores.0.store.name', Store::query()->firstOrFail()->name)
            ->assertJsonMissingPath('data.stores.0.product')
            ->assertJsonMissingPath('data.stores.0.variants');
    }

    public function test_catalog_price_range_matches_one_variant(): void
    {
        $product = Product::factory()->create(['name' => 'Produk Filter Harga']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 10000,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 100000,
        ]);
        StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/catalog/products?min_price=50000&max_price=60000')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_catalog_filter_validation_returns_indonesian_errors(): void
    {
        $this->getJson('/api/v1/catalog/products?min_price=100000&max_price=50000&sort=invalid')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_price', 'sort'])
            ->assertJsonPath('errors.max_price.0', 'Harga maksimum harus lebih besar atau sama dengan harga minimum.')
            ->assertJsonPath('errors.sort.0', 'Urutan produk tidak valid.');
    }

    public function test_catalog_search_can_be_combined_with_sort_and_price_filter(): void
    {
        $product = Product::factory()->create(['name' => 'Keloreena Kombinasi Filter']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 93000,
        ]);
        StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/catalog/products?q=Keloreena&min_price=90000&max_price=100000&sort=price_asc')
            ->assertOk()
            ->assertJsonPath('data.0.product.name', 'Keloreena Kombinasi Filter');
    }

    public function test_catalog_accepts_relevance_sort_as_default_marketplace_sort(): void
    {
        $product = Product::factory()->create(['name' => 'Produk Relevansi']);
        ProductVariant::factory()->create(['product_id' => $product->id]);
        $store = Store::factory()->create();

        StoreProductListing::factory()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'description' => 'Deskripsi listing yang lengkap.',
        ]);

        $this->getJson('/api/v1/catalog/products?sort=relevance')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.product.name', 'Produk Relevansi');
    }

    public function test_catalog_rejects_unknown_marketplace_sort(): void
    {
        $this->getJson('/api/v1/catalog/products?sort=popular')
            ->assertStatus(422)
            ->assertJsonPath('errors.sort.0', 'Urutan produk tidak valid.');
    }

    public function test_catalog_can_filter_by_master_category(): void
    {
        $category = Category::factory()->create(['name' => 'Pencernaan', 'slug' => 'pencernaan']);
        $categorizedProduct = Product::factory()->create(['name' => 'Produk Pencernaan']);
        ProductVariant::factory()->create(['product_id' => $categorizedProduct->id]);
        $categorizedProduct->categories()->attach($category);
        StoreProductListing::factory()->create(['product_id' => $categorizedProduct->id]);

        $response = $this->getJson('/api/v1/catalog/products?category=pencernaan');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Produk Pencernaan')
            ->assertJsonPath('data.0.product.categories.0.slug', 'pencernaan');
    }

    public function test_soft_deleted_product_is_not_visible_in_catalog(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        StoreProductListing::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/catalog/products/'.$product->slug)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
