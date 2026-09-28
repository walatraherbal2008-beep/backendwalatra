<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_add_update_and_remove_a_product_from_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => 12500,
        ]);
        $listing = StoreProductListing::factory()->create([
            'product_id' => $product->id,
        ]);

        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertOk()
            ->assertJsonPath('data.total_items', 2)
            ->assertJsonPath('data.subtotal', '25000.00');

        $itemId = $this->getJson('/api/v1/cart')->json('data.items.0.id');

        $this->patchJson('/api/v1/cart/items/'.$itemId, ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.subtotal', '37500.00');

        $this->deleteJson('/api/v1/cart/items/'.$itemId)
            ->assertOk();

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_cart_item_cannot_use_another_users_cart(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($owner, ['customer-api']);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertOk();

        $itemId = $owner->fresh()->carts()->firstOrFail()->items()->firstOrFail()->id;

        Sanctum::actingAs($otherUser, ['customer-api']);

        $this->patchJson('/api/v1/cart/items/'.$itemId, ['quantity' => 5])
            ->assertNotFound();
    }

    public function test_checkout_preview_does_not_fake_local_stock(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertOk();

        $this->postJson('/api/v1/checkout/preview')
            ->assertStatus(503)
            ->assertJsonPath('data.checkout_available', false);
    }

    public function test_checkout_preview_revalidates_stale_cart_items(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);

        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertOk();

        $listing->update(['status' => 'inactive']);

        $this->postJson('/api/v1/checkout/preview')
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_member_can_add_bundle_to_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['customer-api']);

        $product = Product::factory()->create(['status' => 'active']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'status' => 'active',
        ]);
        $bundle = ProductBundle::factory()->create([
            'status' => 'active',
            'price' => 25000,
        ]);
        ProductBundleItem::factory()->create([
            'product_bundle_id' => $bundle->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
        $listing = StoreBundleListing::factory()->create([
            'product_bundle_id' => $bundle->id,
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/cart/items', [
            'type' => 'bundle',
            'bundle_listing_id' => $listing->id,
            'quantity' => 2,
        ])->assertOk()
            ->assertJsonPath('data.items.0.type', 'bundle')
            ->assertJsonPath('data.items.0.bundle.name', $bundle->name)
            ->assertJsonPath('data.subtotal', '50000.00');
    }
}
