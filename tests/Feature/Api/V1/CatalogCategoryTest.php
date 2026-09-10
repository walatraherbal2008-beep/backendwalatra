<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_categories_returns_active_categories_and_product_count(): void
    {
        $category = Category::factory()->create([
            'name' => 'Pencernaan',
            'slug' => 'pencernaan',
        ]);
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        $product->categories()->attach($category);
        StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/catalog/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Pencernaan')
            ->assertJsonPath('data.0.slug', 'pencernaan')
            ->assertJsonPath('data.0.product_count', 1);
    }

    public function test_catalog_category_detail_returns_not_found_for_inactive_category(): void
    {
        Category::factory()->create([
            'slug' => 'kategori-nonaktif',
            'status' => 'inactive',
        ]);

        $this->getJson('/api/v1/catalog/categories/kategori-nonaktif')
            ->assertNotFound()
            ->assertJsonPath('message', 'Kategori tidak ditemukan.');
    }

    public function test_catalog_category_detail_returns_category_by_slug(): void
    {
        $category = Category::factory()->create([
            'name' => 'Batuk',
            'slug' => 'batuk',
        ]);

        $this->getJson('/api/v1/catalog/categories/batuk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'Batuk')
            ->assertJsonPath('data.slug', 'batuk');
    }
}
