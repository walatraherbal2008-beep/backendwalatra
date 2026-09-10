<?php

namespace Tests\Feature\Models;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $bundleListing = StoreBundleListing::factory()->create([
            'store_id' => $store->id,
            'product_bundle_id' => $bundle->id,
            'description' => 'Deskripsi bundle khusus toko',
        ]);
        $secondBundle = ProductBundle::factory()->create();
        $secondBundleItem = ProductBundleItem::factory()->create([
            'product_bundle_id' => $secondBundle->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $this->assertTrue($store->listings->contains($listing));
        $this->assertTrue($store->bundleListings->contains($bundleListing));
        $this->assertTrue($store->products->contains($product));
        $this->assertTrue($product->variants->contains($variant));
        $this->assertTrue($bundle->items->contains($bundleItem));
        $this->assertTrue($bundle->storeListings->contains($bundleListing));
        $this->assertTrue($bundleItem->variant->is($variant));
        $this->assertTrue($secondBundle->items->contains($secondBundleItem));
        $this->assertTrue($secondBundleItem->variant->is($variant));
    }

    public function test_store_product_listing_supports_multiple_store_images(): void
    {
        Storage::fake('public');

        $listing = StoreProductListing::factory()->create(['status' => 'draft', 'published_at' => null]);

        $listing
            ->addMedia(UploadedFile::fake()->image('product-one.jpg'))
            ->toMediaCollection('images');
        $listing
            ->addMedia(UploadedFile::fake()->image('product-two.jpg'))
            ->toMediaCollection('images');

        $this->assertCount(2, $listing->fresh()->getMedia('images'));
        $this->assertSame('product-one.jpg', $listing->getFirstMedia('images')?->file_name);
    }

    public function test_product_can_have_multiple_master_categories(): void
    {
        $product = Product::factory()->create();
        $digestive = Category::factory()->create(['name' => 'Pencernaan']);
        $immunity = Category::factory()->create(['name' => 'Daya Tahan Tubuh']);

        $product->categories()->sync([$digestive->id, $immunity->id]);

        $this->assertCount(2, $product->fresh()->categories);
        $this->assertTrue($digestive->fresh()->products->contains($product));
        $this->assertTrue($immunity->fresh()->products->contains($product));
    }

    public function test_variant_sku_is_generated_automatically(): void
    {
        $product = Product::factory()->create([
            'product_code' => 'PROD-TEST-SKU',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => '50 Kapsul',
            'sku' => null,
        ]);

        $this->assertSame('SKU-WAL-PROD-TEST-SKU-50-KAPSUL', $variant->sku);
    }

    public function test_store_bundle_listing_supports_multiple_store_images(): void
    {
        Storage::fake('public');

        $listing = StoreBundleListing::factory()->create(['status' => 'draft', 'published_at' => null]);

        $listing
            ->addMedia(UploadedFile::fake()->image('bundle-one.jpg'))
            ->toMediaCollection('images');
        $listing
            ->addMedia(UploadedFile::fake()->image('bundle-two.jpg'))
            ->toMediaCollection('images');

        $this->assertCount(2, $listing->fresh()->getMedia('images'));
        $this->assertSame('bundle-one.jpg', $listing->getFirstMedia('images')?->file_name);
    }
}
