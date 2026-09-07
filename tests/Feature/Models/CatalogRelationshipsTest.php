<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_listing_product_variant_and_bundle_relationships_work(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $bundle = ProductBundle::factory()->create();
        $bundleItem = ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
        $listing = StoreProductListing::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
        ]);

        $this->assertTrue($store->listings->contains($listing));
        $this->assertTrue($store->products->contains($product));
        $this->assertTrue($product->variants->contains($variant));
        $this->assertTrue($bundle->items->contains($bundleItem));
        $this->assertTrue($bundleItem->variant->is($variant));
    }
}
