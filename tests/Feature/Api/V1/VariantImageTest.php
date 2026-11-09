<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\WarehouseStockProvider;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Concerns\WithCheckoutWarehouse;
use Tests\TestCase;

class VariantImageTest extends TestCase
{
    use RefreshDatabase, WithCheckoutWarehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['checkout.mode' => 'manual', 'checkout.stock_mode' => 'unlimited', 'checkout.free_shipping' => true]);
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
    }

    private function product(): array
    {
        $variant = ProductVariant::factory()->withWarehouse()->create(['price' => 25000]);
        $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 1,
        ])->assertOk();

        return [$variant, $listing];
    }

    private function photo($model, string $collection = 'images'): Media
    {
        return $model->addMedia(UploadedFile::fake()->image('photo.png', 40, 40))
            ->toMediaCollection($collection, 'public');
    }

    private function payload(): array
    {
        return ['shipping_address' => ['name' => 'Pembeli', 'phone' => '628123456789',
            'address' => 'Jalan Pengujian 1', 'city' => 'Bandung', 'province' => 'Jawa Barat', 'postal_code' => '40135']];
    }

    private function bundle(ProductVariant $variant): StoreBundleListing
    {
        $bundle = ProductBundle::factory()->create();
        ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id]);
        $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
        $this->postJson('/api/v1/cart/items', ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1])->assertOk();

        return $listing;
    }

    public function test_cart_and_preview_follow_variant_listing_and_master_priority(): void
    {
        [$variant, $listing] = $this->product();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.image_url', null);
        $master = $this->photo($variant->product);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.image_url', $master->getUrl());
        $store = $this->photo($listing);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.image_url', $store->getUrl());
        $own = $this->photo($variant, 'image');
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.image_url', $own->getUrl());
        $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk()
            ->assertJsonPath('data.items.0.image_url', $own->getUrl());
        $variant->clearMediaCollection('image');
        $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk()
            ->assertJsonPath('data.items.0.image_url', $store->getUrl());
    }

    public function test_catalog_and_bundle_component_expose_only_variant_owned_photo(): void
    {
        [$variant] = $this->product();
        $master = $this->photo($variant->product);
        $this->getJson('/api/v1/catalog/products/'.$variant->product->slug)->assertOk()
            ->assertJsonPath('data.variants.0.image_url', null)
            ->assertJsonPath('data.images.0.url', $master->getUrl());
        $own = $this->photo($variant, 'image');
        $this->getJson('/api/v1/catalog/products')->assertOk()
            ->assertJsonPath('data.0.variants.0.image_url', $own->getUrl());
        $this->getJson('/api/v1/catalog/products/'.$variant->product->slug)->assertOk()
            ->assertJsonPath('data.variants.0.image_url', $own->getUrl());
        $listing = $this->bundle($variant);
        $this->getJson('/api/v1/catalog/bundles')->assertOk()
            ->assertJsonPath('data.0.bundle.items.0.variant.image_url', $own->getUrl());
        $this->getJson('/api/v1/catalog/bundles?q='.urlencode($variant->product->name))->assertOk()
            ->assertJsonPath('data.0.bundle.items.0.variant.image_url', $own->getUrl());
        $this->getJson('/api/v1/catalog/bundles/'.$listing->bundle->slug)->assertOk()
            ->assertJsonPath('data.items.0.variant.image_url', $own->getUrl());
    }

    public function test_mixed_cart_bundle_uses_listing_then_component_master_and_never_variant_photo(): void
    {
        [$variant] = $this->product();
        $own = $this->photo($variant, 'image');
        $listing = $this->bundle($variant);
        $bundleItem = CartItem::where('item_type', 'bundle')->sole();
        $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk()
            ->assertJsonPath('data.items.1.image_url', null);
        $master = $this->photo($variant->product);
        $items = collect($this->getJson('/api/v1/cart')->assertOk()->json('data.items'));
        $this->assertSame($master->getUrl(), $items->firstWhere('id', $bundleItem->id)['image_url']);
        $store = $this->photo($listing);
        $preview = $this->postJson('/api/v1/checkout/preview', $this->payload())->assertOk();
        $this->assertSame($store->getUrl(), collect($preview->json('data.items'))->firstWhere('type', 'bundle')['image_url']);
        $this->assertSame($own->getUrl(), collect($preview->json('data.items'))->firstWhere('type', 'product')['image_url']);
    }

    public function test_order_copies_product_and_bundle_photos_and_retry_does_not_duplicate(): void
    {
        [$variant] = $this->product();
        $source = $this->photo($variant, 'image');
        $bundleListing = $this->bundle($variant);
        $bundleSource = $this->photo($bundleListing);
        $originalBytes = Storage::disk('public')->get($source->getPathRelativeToRoot());
        $response = $this->postJson('/api/v1/orders', $this->payload(), ['Idempotency-Key' => 'photo-history'])->assertCreated();
        $order = Order::findOrFail($response->json('data.id'));
        $snapshots = $order->items->map(fn ($item) => $item->getFirstMedia('image'));
        $this->assertCount(2, $snapshots->filter());
        $url = $snapshots[0]->getUrl();
        $this->assertNotSame($source->getUrl(), $url);
        $this->assertNotSame($bundleSource->getUrl(), $snapshots[1]->getUrl());
        $this->assertSame($originalBytes, Storage::disk('public')->get($snapshots[0]->getPathRelativeToRoot()));
        $source->delete();
        $bundleSource->delete();
        $this->photo($variant, 'image');
        foreach ($snapshots as $snapshot) {
            Storage::disk('public')->assertExists($snapshot->getPathRelativeToRoot());
        }
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk()->assertJsonPath('data.items.0.image_url', $url);
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.items.0.image_url', $url);
        $mediaCount = Media::count();
        $this->postJson('/api/v1/orders', $this->payload(), ['Idempotency-Key' => 'photo-history'])->assertOk()
            ->assertJsonPath('data.items.0.image_url', $url);
        $this->assertSame($mediaCount, Media::count());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_missing_source_rolls_back_order_keeps_cart_and_cleans_previous_copy(): void
    {
        [$first] = $this->product();
        $this->photo($first, 'image');
        [$second] = $this->product();
        $missing = $this->photo($second, 'image');
        Storage::disk('public')->delete($missing->getPathRelativeToRoot());
        $before = Storage::disk('public')->allFiles();
        $this->postJson('/api/v1/orders', $this->payload(), ['Idempotency-Key' => 'missing-source'])->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertDatabaseCount('media', 2);
        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('public')->allDirectories('order-item-images'));
    }

    public function test_selected_checkout_only_copies_selected_item_photo(): void
    {
        [$selected] = $this->product();
        $this->photo($selected, 'image');
        $selectedItem = CartItem::query()->sole();
        [$unselected] = $this->product();
        $missing = $this->photo($unselected, 'image');
        Storage::disk('public')->delete($missing->getPathRelativeToRoot());
        $this->postJson('/api/v1/orders', [
            ...$this->payload(), 'cart_item_ids' => [$selectedItem->id],
        ], ['Idempotency-Key' => 'selected-photo'])->assertCreated()->assertJsonCount(1, 'data.items');
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['product_variant_id' => $unselected->id]);
        $this->assertSame(1, Media::where('model_type', OrderItem::class)->count());
    }

    public function test_copy_failure_releases_reserved_stock_in_integrated_checkout(): void
    {
        config(['checkout.mode' => 'integrated', 'checkout.stock_mode' => 'external']);
        [$variant] = $this->product();
        $source = $this->photo($variant, 'image');
        Storage::disk('public')->delete($source->getPathRelativeToRoot());
        $warehouse = Mockery::mock(WarehouseStockProvider::class);
        $warehouse->shouldReceive('checkAvailability')->once()->andReturn(['available' => true, 'items' => []]);
        $warehouse->shouldReceive('reserve')->once()->andReturn('photo-reservation');
        $warehouse->shouldReceive('release')->once()->with('photo-reservation');
        $this->app->instance(WarehouseStockProvider::class, $warehouse);

        $this->postJson('/api/v1/orders', $this->payload(), ['Idempotency-Key' => 'release-stock-photo'])->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_partial_destination_write_is_cleaned_when_copy_fails(): void
    {
        [$variant] = $this->product();
        $this->photo($variant, 'image');
        $before = Storage::disk('public')->allFiles();
        $filesystem = Mockery::mock(Filesystem::class, [app('filesystem')])->makePartial();
        $filesystem->shouldReceive('add')->once()->andReturnUsing(function ($file, Media $media): bool {
            Storage::disk('public')->put($media->getPathRelativeToRoot(), 'partial image');
            throw new RuntimeException('Disk failure during snapshot write');
        });
        $this->app->instance(Filesystem::class, $filesystem);

        $this->postJson('/api/v1/orders', $this->payload(), ['Idempotency-Key' => 'partial-write'])->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('media', 1);
        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles());
    }

    public function test_order_without_photos_and_old_orders_return_null(): void
    {
        $this->product();
        $created = $this->postJson('/api/v1/orders', $this->payload(), ['Idempotency-Key' => 'no-photo'])->assertCreated()
            ->assertJsonPath('data.items.0.image_url', null);
        $this->getJson('/api/v1/orders/'.$created->json('data.id'))->assertOk()->assertJsonPath('data.items.0.image_url', null);
        $this->assertSame(0, Media::where('model_type', OrderItem::class)->count());
    }

    public function test_cart_image_queries_do_not_grow_per_item(): void
    {
        [$first] = $this->product();
        $this->photo($first, 'image');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/cart')->assertOk();
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 3; $i++) {
            [$variant] = $this->product();
            $this->photo($variant, 'image');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(4, 'data.items');
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
