<?php

namespace Tests\Integration;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\StoreBundleListing;
use App\Models\StoreProductListing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CartCheckoutConcurrencyTest extends TestCase
{
    private function assertWorkerIsWaitingForLock(Process $worker): void
    {
        $ready = $worker->waitUntil(fn (): bool => str_contains($worker->getOutput(), 'locking:'));
        $this->assertTrue($ready, $worker->getOutput()."\n".$worker->getErrorOutput());
        preg_match('/locking:(\d+)/', $worker->getOutput(), $matches);
        $waitEvent = null;
        $deadline = microtime(true) + 3;
        do {
            $waitEvent = DB::selectOne('select wait_event_type from pg_stat_activity where pid = ?', [(int) $matches[1]])?->wait_event_type;
            if ($waitEvent !== 'Lock') {
                usleep(10000);
            }
        } while ($waitEvent !== 'Lock' && microtime(true) < $deadline);
        $this->assertSame('Lock', $waitEvent);
        $this->assertTrue($worker->isRunning());
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql'
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_cart_concurrency_test_')) {
            $this->markTestSkipped('Memerlukan database PostgreSQL khusus dengan prefix walatra_cart_concurrency_test_.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['checkout.mode' => 'manual', 'checkout.free_shipping' => true]);
    }

    public function test_cart_mutations_wait_for_checkout_and_cannot_change_or_delete_its_snapshot(): void
    {
        foreach (['add_product', 'add_bundle', 'update', 'delete', 'clear'] as $operation) {
            $user = User::factory()->create();
            Sanctum::actingAs($user, ['customer-api']);
            $variant = ProductVariant::factory()->create(['price' => 15000]);
            $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
            $productPayload = ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 2];
            $this->postJson('/api/v1/cart/items', $productPayload)->assertOk();
            $bundle = ProductBundle::factory()->create(['price' => 40000]);
            ProductBundleItem::factory()->create(['product_bundle_id' => $bundle->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
            $bundleListing = StoreBundleListing::factory()->create(['product_bundle_id' => $bundle->id]);
            $bundlePayload = ['type' => 'bundle', 'bundle_listing_id' => $bundleListing->id, 'quantity' => 1];
            $this->postJson('/api/v1/cart/items', $bundlePayload)->assertOk();
            $productItem = CartItem::query()->where('store_product_listing_id', $listing->id)->sole();
            $bundleItem = CartItem::query()->where('store_bundle_listing_id', $bundleListing->id)->sole();
            $ids = [$productItem->id, $bundleItem->id];
            $payload = ['cart_item_ids' => $ids, 'shipping_address' => [
                'name' => 'Pembeli Bersamaan', 'phone' => '628123456789', 'address' => 'Jalan Pengujian',
                'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135',
            ]];
            $payload['checkout_token'] = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
            [$method, $path, $body] = match ($operation) {
                'add_product' => ['POST', '/api/v1/cart/items', [...$productPayload, 'quantity' => 1]],
                'add_bundle' => ['POST', '/api/v1/cart/items', $bundlePayload],
                'update' => ['PATCH', '/api/v1/cart/items/'.$productItem->id, ['quantity' => 9]],
                'delete' => ['DELETE', '/api/v1/cart/items/'.$productItem->id, []],
                'clear' => ['DELETE', '/api/v1/cart', []],
            };
            $worker = new Process([PHP_BINARY, base_path('tests/Support/cart-mutation-worker.php')], base_path());
            $worker->setTimeout(15);
            $worker->setInput(json_encode(['user_id' => $user->id, 'method' => $method, 'path' => $path, 'payload' => $body], JSON_THROW_ON_ERROR));

            DB::beginTransaction();
            try {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $worker->start();
                $this->assertWorkerIsWaitingForLock($worker);

                $response = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'concurrent-'.$operation])
                    ->assertCreated()->assertJsonPath('data.total', '70000.00')->assertJsonCount(2, 'data.items');
                $orderId = $response->json('data.id');
                $this->assertSame(0, CartItem::query()->whereIn('id', $ids)->count());
                DB::commit();
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                preg_match('/^result:(.+)$/m', $worker->getOutput(), $result);
                $status = json_decode($result[1], true, 512, JSON_THROW_ON_ERROR)['status'];
                $this->assertSame($operation === 'update' ? 404 : 200, $status);

                $order = Order::findOrFail($orderId);
                $this->assertSame(2, $order->items()->where('item_type', 'product')->sole()->quantity);
                $this->assertSame(1, $order->items()->where('item_type', 'bundle')->sole()->quantity);
                $remaining = $user->carts()->sole()->items()->get();
                if (str_starts_with($operation, 'add_')) {
                    $this->assertCount(1, $remaining);
                    $this->assertSame(1, $remaining->sole()->quantity);
                    $this->assertNotContains($remaining->sole()->id, $ids);
                } else {
                    $this->assertCount(0, $remaining);
                }
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_master_product_and_bundle_price_updates_wait_until_order_is_committed(): void
    {
        foreach (['product', 'bundle'] as $type) {
            $user = User::factory()->create();
            Sanctum::actingAs($user, ['customer-api']);
            $variant = ProductVariant::factory()->create(['price' => 15000]);
            if ($type === 'product') {
                $master = $variant;
                $listing = StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
                $cartPayload = ['listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 1];
            } else {
                $master = ProductBundle::factory()->create(['price' => 15000]);
                ProductBundleItem::factory()->create(['product_bundle_id' => $master->id, 'product_variant_id' => $variant->id]);
                $listing = StoreBundleListing::factory()->create(['product_bundle_id' => $master->id]);
                $cartPayload = ['type' => 'bundle', 'bundle_listing_id' => $listing->id, 'quantity' => 1];
            }
            $this->postJson('/api/v1/cart/items', $cartPayload)->assertOk();
            $payload = ['shipping_address' => [
                'name' => 'Pembeli Harga', 'phone' => '628123456789', 'address' => 'Jalan Pengujian',
                'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135',
            ]];
            $payload['checkout_token'] = $this->postJson('/api/v1/checkout/preview', $payload)->assertOk()->json('data.checkout_token');
            $worker = new Process([PHP_BINARY, base_path('tests/Support/cart-mutation-worker.php')], base_path());
            $worker->setTimeout(15);
            $worker->setInput(json_encode(['operation' => 'price', 'user_id' => $user->id, 'type' => $type, 'id' => $master->id], JSON_THROW_ON_ERROR));
            DB::beginTransaction();
            try {
                $orderId = $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'locked-price-'.$type])
                    ->assertCreated()->assertJsonPath('data.total', '15000.00')->json('data.id');
                $worker->start();
                $this->assertWorkerIsWaitingForLock($worker);
                DB::commit();
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertSame('99000.00', $master->fresh()->price);
                $order = Order::findOrFail($orderId);
                $this->assertSame('15000.00', $order->total);
                $this->assertSame('15000.00', $order->items()->sole()->unit_price);
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
