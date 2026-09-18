<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_unified_catalog_returns_product_and_bundle(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['status' => 'active']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
        StoreBundleListing::factory()->create(['store_id' => $store->id, 'product_bundle_id' => $bundle->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?per_page=50')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['type' => 'product'])
            ->assertJsonFragment(['type' => 'bundle']);
    }

    public function test_unified_catalog_type_filter_and_visibility_rules_work(): void
    {
        $store = Store::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['status' => 'active']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);
        StoreProductListing::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'active']);
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
        StoreBundleListing::factory()->create(['store_id' => $store->id, 'product_bundle_id' => $bundle->id, 'status' => 'active']);

        $this->getJson('/api/v1/catalog?type=bundle')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'bundle');

        $product->update(['status' => 'inactive']);

        $this->getJson('/api/v1/catalog?type=product')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unified_catalog_validation_uses_indonesian_messages(): void
    {
        $this->getJson('/api/v1/catalog?type=invalid&sort=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['type', 'sort']);
    }
}
