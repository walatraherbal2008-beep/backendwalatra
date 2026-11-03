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
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.mode' => 'manual']);
        Http::preventStrayRequests();
    }

    private function catalog(float $price = 15000): array
    {
        $store = Store::factory()->create(['name' => 'Toko Katalog Audit']);
        $category = Category::factory()->create(['name' => 'Pencernaan X']);
        $product = Product::factory()->create(['name' => 'Herbal Lambung 100ml']);
        $product->categories()->attach($category);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'name' => 'Kemasan 100ml', 'sku' => 'AUDIT-SKU-100ML', 'price' => $price,
        ]);
        $listing = StoreProductListing::factory()->create([
            'store_id' => $store->id, 'product_id' => $product->id, 'description' => 'Untuk lambung kronis',
        ]);

        return [$product, $variant, $listing, $category, $store];
    }

    private function bundle(ProductVariant $variant, Store $store, float $price = 40000): array
    {
        $bundle = ProductBundle::factory()->create(['name' => 'Paket Audit', 'price' => $price]);
        $item = ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 2,
        ]);
        StoreBundleListing::factory()->create(['store_id' => $store->id, 'product_bundle_id' => $bundle->id]);

        return [$bundle, $item];
    }

    public function test_catalog_routes_are_public_and_active_data_can_be_read(): void
    {
        [$product, $variant, , $category, $store] = $this->catalog();
        [$bundle] = $this->bundle($variant, $store);
        foreach (['/catalog', '/catalog/products', '/catalog/bundles', '/catalog/categories',
            '/catalog/products/'.$product->slug, '/catalog/bundles/'.$bundle->slug,
            '/catalog/categories/'.$category->slug] as $path) {
            $this->getJson('/api/v1'.$path)->assertOk()->assertJsonPath('success', true);
        }
        foreach (['products', 'bundles', 'categories'] as $type) {
            $this->getJson('/api/v1/catalog/'.$type.'/audit-not-found')->assertNotFound();
        }
    }

    public function test_product_search_with_one_character_does_not_return_server_error(): void
    {
        $this->catalog();
        $this->getJson('/api/v1/catalog?q=a')->assertOk();
        $this->getJson('/api/v1/catalog/products?q=a')->assertOk();
    }

    public function test_postgres_product_search_finds_category_matches(): void
    {
        [$product] = $this->catalog();
        $this->getJson('/api/v1/catalog/products?q=pencernaan%20x')->assertOk()
            ->assertJsonPath('data.0.product.id', $product->id);
    }

    public function test_postgres_product_search_finds_tokens_across_name_and_description(): void
    {
        [$product] = $this->catalog();
        $this->getJson('/api/v1/catalog/products?q=kronis%20herbal')->assertOk()
            ->assertJsonPath('data.0.product.id', $product->id);
    }

    public static function priceFilterEndpoints(): array
    {
        return [['/catalog/products'], ['/catalog']];
    }

    #[DataProvider('priceFilterEndpoints')]
    public function test_maximum_price_can_be_used_without_minimum_price(string $path): void
    {
        $this->catalog();
        $this->getJson('/api/v1'.$path.'?max_price=20000')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_soft_deleted_bundle_is_hidden_in_unified_catalog(): void
    {
        [, $variant, , , $store] = $this->catalog();
        [$bundle] = $this->bundle($variant, $store);
        $bundle->delete();
        $this->getJson('/api/v1/catalog/bundles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalog?type=bundle')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unified_product_price_ignores_soft_deleted_variant(): void
    {
        [$product] = $this->catalog(40000);
        $cheap = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Kemasan lama', 'price' => 10000]);
        $cheap->delete();
        $data = $this->getJson('/api/v1/catalog?type=product')->assertOk()->json('data.0');
        $this->assertEquals(40000, (float) $data['price']['min']);
    }

    public function test_bundle_without_remaining_components_is_hidden_in_unified_catalog(): void
    {
        [, $variant, , , $store] = $this->catalog();
        [, $item] = $this->bundle($variant, $store);
        $item->delete();
        $this->getJson('/api/v1/catalog/bundles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalog?type=bundle')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleted_variant_sku_does_not_match_public_product_search(): void
    {
        [$product] = $this->catalog();
        $oldVariant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'name' => 'Kemasan lama', 'sku' => 'DELETED-ONLY-XYZ',
        ]);
        $oldVariant->delete();
        $this->getJson('/api/v1/catalog/products?q=DELETED-ONLY-XYZ')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalog?q=DELETED-ONLY-XYZ')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_zero_price_bundle_is_not_advertised_as_purchasable(): void
    {
        [, $variant, , , $store] = $this->catalog();
        [$bundle] = $this->bundle($variant, $store, 0);
        $this->getJson('/api/v1/catalog/bundles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalog/bundles/'.$bundle->slug)->assertNotFound();
        $this->getJson('/api/v1/catalog?type=bundle')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_inactive_products_remain_hidden_in_public_catalogs(): void
    {
        [$product] = $this->catalog();
        $product->update(['status' => 'inactive']);
        $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalog?type=product')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalog/products/'.$product->slug)->assertNotFound();
    }
}
