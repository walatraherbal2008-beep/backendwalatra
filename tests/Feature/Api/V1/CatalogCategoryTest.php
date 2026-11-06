<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ->assertJsonPath('data.0.icon_url', null)
            ->assertJsonPath('data.0.name', 'Pencernaan')
            ->assertJsonPath('data.0.slug', 'pencernaan')
            ->assertJsonPath('data.0.product_count', 1);
    }

    public function test_parent_and_child_icons_are_returned_in_list_and_detail(): void
    {
        Storage::fake('public');
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $parentIcon = $parent->addMedia(UploadedFile::fake()->image('parent.png'))->toMediaCollection('icon');
        $childIcon = $child->addMedia(UploadedFile::fake()->image('child.png'))->toMediaCollection('icon');

        $this->getJson('/api/v1/catalog/categories')->assertOk()
            ->assertJsonPath('data.0.icon_url', $parentIcon->getUrl())
            ->assertJsonPath('data.0.children.0.icon_url', $childIcon->getUrl());
        $this->getJson('/api/v1/catalog/categories/'.$parent->slug)->assertOk()
            ->assertJsonPath('data.icon_url', $parentIcon->getUrl())
            ->assertJsonPath('data.children.0.icon_url', $childIcon->getUrl());
        $this->getJson('/api/v1/catalog/categories/'.$child->slug)->assertOk()
            ->assertJsonPath('data.icon_url', $childIcon->getUrl());
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
