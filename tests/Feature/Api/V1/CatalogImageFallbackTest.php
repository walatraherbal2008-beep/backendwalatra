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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_listing_without_images_falls_back_to_master_product_image(): void
    {
        Storage::fake('public');
        $product = $this->activeProduct('Produk Master Fallback');
        $masterMedia = $product
            ->addMedia(UploadedFile::fake()->image('master.jpg', 1200, 800))
            ->toMediaCollection('images');
        $this->activeListingFor($product);

        $this->getJson('/api/v1/catalog?q=Produk Master Fallback')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', $masterMedia->getUrl())
            ->assertJsonPath('data.0.image.url', $masterMedia->getUrl())
            ->assertJsonPath('data.0.image.thumb', $masterMedia->getUrl('thumb'));
    }

    public function test_product_listing_with_own_images_takes_precedence_over_master(): void
    {
        Storage::fake('public');
        $product = $this->activeProduct('Produk Dua Gambar');
        $product
            ->addMedia(UploadedFile::fake()->image('master.jpg'))
            ->toMediaCollection('images');
        $listing = $this->activeListingFor($product);
        $listingMedia = $listing
            ->addMedia(UploadedFile::fake()->image('etalase-toko.jpg'))
            ->toMediaCollection('images');

        $this->getJson('/api/v1/catalog?q=Produk Dua Gambar')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', $listingMedia->getUrl())
            ->assertJsonPath('data.0.image.url', $listingMedia->getUrl());
    }

    public function test_product_list_stores_offer_gallery_falls_back_to_master_image(): void
    {
        Storage::fake('public');
        $product = $this->activeProduct('Produk List Fallback');
        $masterMedia = $product
            ->addMedia(UploadedFile::fake()->image('master-list.jpg'))
            ->toMediaCollection('images');
        $this->activeListingFor($product);

        $this->getJson('/api/v1/catalog/products?q=Produk List Fallback')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.images')
            ->assertJsonPath('data.0.images.0.url', $masterMedia->getUrl());
    }

    public function test_product_detail_stores_offer_gallery_falls_back_to_master_image(): void
    {
        Storage::fake('public');
        $product = $this->activeProduct('Produk Detail Fallback');
        $masterMedia = $product
            ->addMedia(UploadedFile::fake()->image('master-detail.jpg'))
            ->toMediaCollection('images');
        $this->activeListingFor($product);

        $this->getJson('/api/v1/catalog/products/'.$product->slug)
            ->assertOk()
            ->assertJsonCount(1, 'data.stores.0.images')
            ->assertJsonPath('data.stores.0.images.0.url', $masterMedia->getUrl());
    }

    public function test_bundle_falls_back_to_first_component_product_image(): void
    {
        Storage::fake('public');
        $product = $this->activeProduct('Produk Komponen Bundle');
        $masterMedia = $product
            ->addMedia(UploadedFile::fake()->image('master-bundle.jpg'))
            ->toMediaCollection('images');
        $variant = $product->variants()->firstOrFail();
        $bundle = ProductBundle::factory()->create(['status' => 'active']);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
        ]);
        StoreBundleListing::factory()->create([
            'store_id' => Store::factory()->create(['status' => 'active'])->id,
            'product_bundle_id' => $bundle->id,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/catalog?type=bundle')
            ->assertOk()
            ->assertJsonPath('data.0.image.url', $masterMedia->getUrl());

        $this->getJson('/api/v1/catalog/bundles/'.$bundle->slug)
            ->assertOk()
            ->assertJsonPath('data.stores.0.images.0.url', $masterMedia->getUrl());
    }

    public function test_images_stay_null_when_neither_listing_nor_master_has_media(): void
    {
        $product = $this->activeProduct('Produk Tanpa Gambar');
        $this->activeListingFor($product);

        $this->getJson('/api/v1/catalog?q=Produk Tanpa Gambar')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', null)
            ->assertJsonPath('data.0.image.url', null)
            ->assertJsonPath('data.0.image.thumb', null);
    }

    private function activeProduct(string $name): Product
    {
        $product = Product::factory()->create(['name' => $name, 'status' => 'active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'status' => 'active']);

        return $product;
    }

    private function activeListingFor(Product $product): StoreProductListing
    {
        return StoreProductListing::factory()->create([
            'store_id' => Store::factory()->create(['status' => 'active'])->id,
            'product_id' => $product->id,
            'status' => 'active',
        ]);
    }
}
