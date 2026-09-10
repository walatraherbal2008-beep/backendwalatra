<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_bundle_list_returns_active_bundle_listing_with_items(): void
    {
        [$bundle, $store] = $this->createPublishedBundle();

        $this->getJson('/api/v1/catalog/bundles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.bundle.name', $bundle->name)
            ->assertJsonPath('data.0.bundle.items.0.variant.sku', 'SKU-TEST-BUNDLE-01')
            ->assertJsonPath('data.0.store.name', $store->name);
    }

    public function test_catalog_bundle_detail_returns_bundle_and_store_offers(): void
    {
        [$bundle] = $this->createPublishedBundle();

        $this->getJson('/api/v1/catalog/bundles/'.$bundle->slug)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', $bundle->name)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonCount(1, 'data.stores');
    }

    public function test_catalog_bundle_filter_and_validation_work(): void
    {
        [$bundle] = $this->createPublishedBundle();

        $this->getJson('/api/v1/catalog/bundles?q=Paket&sort=price_desc&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.bundle.slug', $bundle->slug);

        $this->getJson('/api/v1/catalog/bundles?sort=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('sort');
    }

    public function test_inactive_bundle_is_not_visible_in_catalog(): void
    {
        [$bundle] = $this->createPublishedBundle(['status' => 'inactive']);

        $this->getJson('/api/v1/catalog/bundles/'.$bundle->slug)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_bundle_with_inactive_component_is_not_visible_in_catalog(): void
    {
        [$bundle] = $this->createPublishedBundle();
        $item = $bundle->items()->with('variant')->firstOrFail();
        $item->variant->update(['status' => 'inactive']);

        $this->getJson('/api/v1/catalog/bundles/'.$bundle->slug)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    /** @return array{0: ProductBundle, 1: Store} */
    private function createPublishedBundle(array $bundleAttributes = []): array
    {
        $product = Product::factory()->create(['status' => 'active']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-TEST-BUNDLE-01',
            'status' => 'active',
        ]);
        $bundle = ProductBundle::factory()->create($bundleAttributes);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
        $store = Store::factory()->create(['status' => 'active']);
        $listingStatus = ($bundleAttributes['status'] ?? 'active') === 'active' ? 'active' : 'draft';

        StoreBundleListing::factory()->create([
            'store_id' => $store->id,
            'product_bundle_id' => $bundle->id,
            'status' => $listingStatus,
            'published_at' => $listingStatus === 'active' ? now() : null,
        ]);

        return [$bundle, $store];
    }
}
