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
use App\Services\Marketplace\StoreListingReactivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

    public function test_category_cannot_be_its_own_parent(): void
    {
        $category = Category::factory()->create();

        $category->parent_id = $category->id;

        $this->expectException(ValidationException::class);
        $category->save();
    }

    public function test_category_cannot_create_a_parent_cycle(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create(['parent_id' => $first->id]);

        $first->parent_id = $second->id;

        $this->expectException(ValidationException::class);
        $first->save();
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

    public function test_product_code_is_generated_automatically_and_immutable_when_name_changes(): void
    {
        $product = Product::factory()->create([
            'product_code' => null,
            'name' => 'Produk Herbal Otomatis',
        ]);
        $productCode = $product->product_code;

        $this->assertMatchesRegularExpression('/^PRD-WAL-[A-Z0-9]{8}$/', $productCode);

        $product->update(['name' => 'Produk Herbal Diperbarui']);

        $this->assertSame($productCode, $product->fresh()->product_code);

        $this->expectException(ValidationException::class);
        $product->update(['product_code' => 'PRD-WAL-CHANGED']);
    }

    public function test_product_code_from_soft_deleted_product_is_not_reused(): void
    {
        $deletedProduct = Product::factory()->create(['product_code' => null]);
        $deletedCode = $deletedProduct->product_code;
        $deletedProduct->delete();

        $newProduct = Product::factory()->create(['product_code' => null]);

        $this->assertNotSame($deletedCode, $newProduct->product_code);
    }

    public function test_product_cannot_be_activated_without_an_active_variant(): void
    {
        $product = Product::factory()->create(['status' => 'draft']);

        $this->expectException(ValidationException::class);
        $product->update(['status' => 'active']);
    }

    public function test_product_can_be_activated_after_an_active_variant_is_added(): void
    {
        $product = Product::factory()->create(['status' => 'draft']);
        $category = Category::factory()->create(['status' => 'active']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
        ]);
        $product->categories()->attach($category);

        $product->update(['status' => 'active']);

        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_product_cannot_be_activated_without_an_active_category(): void
    {
        $product = Product::factory()->create(['status' => 'draft']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
        ]);

        $this->expectException(ValidationException::class);
        $product->update(['status' => 'active']);
    }

    public function test_product_cannot_be_activated_with_only_an_inactive_category(): void
    {
        $product = Product::factory()->create(['status' => 'draft']);
        $category = Category::factory()->create(['status' => 'inactive']);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
        ]);
        $product->categories()->attach($category);

        $this->expectException(ValidationException::class);
        $product->update(['status' => 'active']);
    }

    public function test_category_cannot_be_deactivated_when_used_by_an_active_product(): void
    {
        $category = Category::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['status' => 'active']);
        $product->categories()->attach($category);

        $this->expectException(ValidationException::class);
        $category->update(['status' => 'inactive']);
    }

    public function test_category_cannot_be_deleted_when_used_by_an_active_product(): void
    {
        $category = Category::factory()->create(['status' => 'active']);
        $product = Product::factory()->create(['status' => 'active']);
        $product->categories()->attach($category);

        $this->expectException(ValidationException::class);
        $category->delete();
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

    public function test_deleted_product_listing_is_restored_instead_of_duplicated(): void
    {
        $listing = StoreProductListing::factory()->create([
            'status' => 'draft',
            'published_at' => null,
            'description' => 'Deskripsi lama',
        ]);
        $listing->delete();

        $restored = app(StoreListingReactivationService::class)->createOrRestoreProductListing([
            'store_id' => $listing->store_id,
            'product_id' => $listing->product_id,
            'description' => 'Deskripsi baru',
            'status' => 'draft',
            'sort_order' => 3,
            'published_at' => null,
        ]);

        $this->assertSame($listing->id, $restored->id);
        $this->assertFalse($restored->fresh()->trashed());
        $this->assertSame('Deskripsi baru', $restored->fresh()->description);
        $this->assertSame(3, $restored->fresh()->sort_order);
        $this->assertDatabaseCount('store_product_listings', 1);
    }

    public function test_deleted_bundle_listing_is_restored_instead_of_duplicated(): void
    {
        $listing = StoreBundleListing::factory()->create([
            'status' => 'draft',
            'published_at' => null,
        ]);
        $listing->delete();

        $restored = app(StoreListingReactivationService::class)->createOrRestoreBundleListing([
            'store_id' => $listing->store_id,
            'product_bundle_id' => $listing->product_bundle_id,
            'description' => 'Bundle dipublikasikan kembali',
            'status' => 'draft',
            'sort_order' => 2,
            'published_at' => null,
        ]);

        $this->assertSame($listing->id, $restored->id);
        $this->assertFalse($restored->fresh()->trashed());
        $this->assertSame('Bundle dipublikasikan kembali', $restored->fresh()->description);
        $this->assertDatabaseCount('store_bundle_listings', 1);
    }
}
