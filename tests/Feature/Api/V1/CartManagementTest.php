<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\WarehouseDirectoryProvider;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\CartItem;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\WithCheckoutWarehouse;
use Tests\TestCase;

class CartManagementTest extends TestCase
{
    use RefreshDatabase, WithCheckoutWarehouse;

    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout.mode' => 'manual', 'checkout.stock_mode' => 'unlimited', 'checkout.free_shipping' => true]);
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
    }

    private function add(bool $bundle = false, int $quantity = 2): array
    {
        $variant = ProductVariant::factory()->withWarehouse()->create(['price' => 15000]);
        if ($bundle) {
            $master = ProductBundle::factory()->create(['price' => 40000]);
            ProductBundleItem::factory()->create(['product_bundle_id' => $master->id, 'product_variant_id' => $variant->id]);
            $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $master->id]);
            $body = ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => $quantity];
        } else {
            $master = $variant->product;
            $listing = StoreProductListing::factory()->create(['product_id' => $master->id]);
            $body = ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => $quantity];
        }
        $this->postJson('/api/v1/cart/items', $body)->assertOk();

        return [CartItem::latest('id')->firstOrFail(), $listing, $variant, $master, $body];
    }

    private function payload(array $ids): array
    {
        return ['cart_item_ids' => $ids, 'shipping_address' => ['name' => 'Pembeli Cart', 'phone' => '081234567890',
            'address' => 'Jalan Pengujian', 'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135']];
    }

    public function test_first_cart_read_for_a_new_customer_returns_200_with_empty_selection(): void
    {
        $this->assertDatabaseCount('carts', 0);
        foreach (range(1, 2) as $ignored) {
            $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items')
                ->assertJsonPath('data.total_items', 0)->assertJsonPath('data.subtotal', '0.00')
                ->assertJsonPath('data.selection', ['cart_item_ids' => [], 'line_count' => 0,
                    'total_items' => 0, 'subtotal' => '0.00', 'unavailable_item_ids' => []]);
        }
        $this->assertDatabaseCount('carts', 1);
    }

    public static function unavailableCases(): array
    {
        $cases = [];
        foreach ([false, true] as $bundle) {
            foreach (['listing_deleted', 'master_deleted', 'variant_deleted', 'product_deleted', 'listing_inactive', 'variant_inactive', 'store_inactive', 'master_inactive'] as $change) {
                $cases[($bundle ? 'bundle_' : 'product_').$change] = [$bundle, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('unavailableCases')]
    public function test_unavailable_items_remain_readable_and_removable_but_cannot_be_purchased(bool $bundle, string $change): void
    {
        [$item, $listing, $variant, $master, $body] = $this->add($bundle);
        $payload = $this->payload([$item->id]);
        $payload['checkout_token'] = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
        match ($change) {
            'listing_deleted' => $listing->delete(),
            'master_deleted' => $master->delete(),
            'variant_deleted' => $variant->delete(),
            'product_deleted' => $variant->product->delete(),
            'listing_inactive' => $listing->update(['status' => 'inactive']),
            'variant_inactive' => $variant->update(['status' => 'inactive']),
            'store_inactive' => $listing->store->update(['status' => 'inactive']),
            'master_inactive' => $master->update(['status' => 'inactive']),
        };
        $cart = $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.is_available', false)
            ->assertJsonPath('data.items.0.availability_message', 'Tidak tersedia');
        $cart->assertJsonPath($bundle ? 'data.items.0.bundle.name' : 'data.items.0.product.name', $master->name)
            ->assertJsonPath('data.items.0.store.name', $listing->store->name);
        $this->patchJson('/api/v1/cart/items/'.$item->id, ['quantity' => 3])->assertStatus(409)->assertJsonPath('code', 'STALE_CART');
        $this->postJson('/api/v1/cart/items', $body)->assertUnprocessable();
        $this->postJson('/api/v1/checkout/preview', $payload)->assertStatus(409)->assertJsonPath('code', 'STALE_CART');
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'unavailable'])->assertStatus(409)->assertJsonPath('code', 'STALE_CART');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);
        $this->deleteJson('/api/v1/cart/items/'.$item->id)->assertOk();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_selection_uses_available_selected_rows_without_filtering_the_cart_or_calling_warehouse(): void
    {
        [$product] = $this->add(quantity: 2);
        [$bundle] = $this->add(bundle: true, quantity: 3);
        [$stale, $listing] = $this->add();
        $listing->delete();
        $directory = Mockery::mock(WarehouseDirectoryProvider::class);
        $directory->shouldNotReceive('list');
        $this->app->instance(WarehouseDirectoryProvider::class, $directory);
        $ids = [$product->id, $bundle->id, $stale->id];
        $this->getJson('/api/v1/cart?'.http_build_query(['cart_item_ids' => $ids]))->assertOk()
            ->assertJsonCount(3, 'data.items')->assertJsonPath('data.total_items', 7)->assertJsonPath('data.subtotal', '180000.00')
            ->assertJsonPath('data.selection', ['cart_item_ids' => $ids, 'line_count' => 2, 'total_items' => 5,
                'subtotal' => '150000.00', 'unavailable_item_ids' => [$stale->id]]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.selection', [
            'cart_item_ids' => [], 'line_count' => 0, 'total_items' => 0, 'subtotal' => '0.00', 'unavailable_item_ids' => [],
        ]);
        $this->getJson('/api/v1/cart?'.http_build_query(['cart_item_ids' => [$bundle->id]]))->assertOk()
            ->assertJsonPath('data.selection.subtotal', '120000.00');
    }

    public function test_selection_validation_and_cart_ownership(): void
    {
        [$item] = $this->add();
        $owner = auth()->user();
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        [$foreign] = $this->add();
        Sanctum::actingAs($owner, ['customer-api']);
        foreach (['x', [0], [$item->id, $item->id], [$foreign->id], [999999], ['x'], ['named' => $item->id]] as $ids) {
            $this->getJson('/api/v1/cart?'.http_build_query(['cart_item_ids' => $ids]))->assertUnprocessable();
        }
        $this->patchJson('/api/v1/cart/items/'.$foreign->id, ['quantity' => 3])->assertNotFound();
        $this->deleteJson('/api/v1/cart/items/'.$foreign->id)->assertOk();
        $this->assertDatabaseHas('cart_items', ['id' => $foreign->id]);
    }

    public function test_missing_relations_serialize_as_null_instead_of_breaking_cart(): void
    {
        foreach (['variant', 'product', 'listing', 'bundleListing', 'bundle', 'component'] as $missing) {
            $bundle = in_array($missing, ['bundleListing', 'bundle', 'component'], true);
            [$item] = $this->add(bundle: $bundle);
            $item->load(['variant.product', 'listing.store', 'bundleListing.store', 'bundleListing.bundle.items.variant.product']);
            match ($missing) {
                'variant', 'listing', 'bundleListing' => $item->setRelation($missing, null),
                'product' => $item->variant->setRelation('product', null),
                'bundle' => $item->bundleListing->setRelation('bundle', null),
                'component' => $item->bundleListing->bundle->items->first()->setRelation('variant', null),
            };
            $cart = $item->cart->setRelation('items', new Collection([$item]));
            $data = (new CartResource($cart))->response()->getData(true)['data']['items'][0];
            $this->assertFalse($data['is_available']);
            if ($missing === 'component') {
                $this->assertNull($data['bundle']['items'][0]['variant']);
                $this->assertNull($data['bundle']['items'][0]['product']);
            } else {
                $field = match ($missing) {
                    'variant', 'product' => 'product', 'listing' => 'store', default => 'bundle',
                };
                $this->assertNull($data[$field]);
            }
        }
    }

    public function test_preview_summary_reprices_selection_and_checkout_keeps_unselected_deleted_item(): void
    {
        [$item, , $variant] = $this->add();
        [$stale, $listing] = $this->add(bundle: true);
        $listing->delete();
        $variant->update(['price' => 20000]);
        $this->getJson('/api/v1/cart?'.http_build_query(['cart_item_ids' => [$item->id]]))->assertOk()
            ->assertJsonPath('data.selection.subtotal', '30000.00');
        $payload = $this->payload([$item->id]);
        $preview = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()
            ->assertJsonPath('data.price_changed', true)->assertJsonPath('data.selection.subtotal', '40000.00')
            ->assertJsonPath('data.selection.total_items', 2)->assertJsonPath('data.selection.line_count', 1);
        $payload['checkout_token'] = $preview->json('data.checkout_token');
        $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'selection'])->assertCreated()->assertJsonPath('data.total', '40000.00');
        $this->assertDatabaseHas('cart_items', ['id' => $stale->id]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_all_modes_require_preview_and_idempotent_retry_survives_expiration_or_omitted_token(): void
    {
        foreach (['manual', 'integrated', 'simulation'] as $mode) {
            config(['checkout.mode' => $mode === 'manual' ? 'manual' : 'integrated', 'checkout.simulation.enabled' => $mode === 'simulation']);
            [$item] = $this->add();
            $payload = $this->payload([$item->id]);
            $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $mode])->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
            $payload['checkout_token'] = 'invalid';
            $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $mode])->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
            $payload['checkout_token'] = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
            $id = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $mode])->assertCreated()->json('data.id');
            $this->travel(16)->minutes();
            $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $mode])->assertOk()->assertJsonPath('data.id', $id);
            unset($payload['checkout_token']);
            $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $mode])->assertOk()->assertJsonPath('data.id', $id);
        }
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_product_and_bundle_quantity_responses_are_capped_and_patch_replaces_quantity(): void
    {
        foreach ([false, true] as $bundle) {
            [$item, , , , $body] = $this->add($bundle, 97);
            $body['quantity'] = 10;
            $this->postJson('/api/v1/cart/items', $body)->assertOk()->assertJsonPath('data.items.0.quantity', 99);
            $this->patchJson('/api/v1/cart/items/'.$item->id, ['quantity' => 100])->assertUnprocessable();
            $response = $this->patchJson('/api/v1/cart/items/'.$item->id, ['quantity' => 3])->assertOk();
            $this->assertSame(3, collect($response->json('data.items'))->firstWhere('id', $item->id)['quantity']);
            $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 3]);
        }
    }

    public function test_cart_query_count_stays_constant_with_more_items_and_owned_stores(): void
    {
        foreach ([false, true] as $bundle) {
            $owner = User::factory()->create(['email_verified_at' => now()]);
            $owner->assignRole(Role::findOrCreate('store_admin', 'web'));
            [, $listing] = $this->add(bundle: $bundle);
            $listing->store->update(['owner_user_id' => $owner->id]);
            $listing->delete();
        }
        DB::enableQueryLog();
        $this->getJson('/api/v1/cart')->assertOk();
        $first = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 4) as $ignored) {
            $owner = User::factory()->create(['email_verified_at' => now()]);
            $owner->assignRole('store_admin');
            [, $listing] = $this->add(bundle: true);
            $listing->store->update(['owner_user_id' => $owner->id]);
            $listing->delete();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/cart')->assertOk();
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($first, $second);
    }
}
