<?php

namespace Tests\Audit;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CheckoutLockAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql'
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_checkout_lock_audit_')) {
            $this->markTestSkipped('Requires an isolated PostgreSQL audit database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config([
            'app.debug' => false, 'checkout.mode' => 'manual', 'checkout.free_shipping' => true,
            'checkout.stock_mode' => 'check_only', 'checkout.warehouse_selection' => 'nearest_available',
            'checkout.preferred_warehouse_code' => null,
            'services.warehouse_remaining_stock.enabled' => true,
            'services.warehouse_remaining_stock.base_url' => 'https://stock.audit.test',
        ]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => '', 'data' => ['jumlah' => 1]])]);
        foreach (['W1' => 107.6, 'W2' => 108.2, 'W3' => 109.0] as $code => $longitude) {
            Warehouse::create([
                'code' => $code, 'realname' => $code, 'codename' => $code, 'initial' => $code,
                'area_group' => 'pulaujawa', 'latitude' => -6.9, 'longitude' => $longitude,
                'is_active' => true, 'is_listed' => true, 'synced_at' => now(),
            ]);
        }
    }

    public static function scenarios(): array
    {
        return [
            'different_products_same_warehouse' => ['shared_warehouse'],
            'different_selected_warehouses_unused_overlap' => ['unused_overlap'],
            'same_variant_blocks_at_pricing' => ['same_variant'],
            'disjoint_candidates_do_not_block' => ['disjoint'],
            'preview_does_not_take_checkout_locks' => ['preview'],
            'database_lock_timeout_returns_500' => ['lock_timeout'],
            'upstream_failure_rolls_back_and_releases_locks' => ['upstream_failure'],
            'invalid_checkout_token_still_holds_warehouse_locks' => ['invalid_token'],
        ];
    }

    private function prepareCustomer(string $productCode, array $codes, float $longitude, ?ProductVariant $variant = null): array
    {
        $customer = User::factory()->create();
        if ($variant === null) {
            $product = Product::factory()->create(['product_code' => $productCode]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 15000]);
            foreach ($codes as $code) {
                $variant->warehouseAssignments()->create(['warehouse_code' => $code]);
            }
        }
        $listing = StoreProductListing::where('product_id', $variant->product_id)->first()
            ?? StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        Sanctum::actingAs($customer, ['customer-api']);
        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 1,
        ])->assertOk();
        $payload = ['shipping_address' => [
            'name' => 'Audit Checkout', 'phone' => '628123456789', 'address' => 'Jalan Pengujian',
            'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135',
            'latitude' => -6.9, 'longitude' => $longitude,
        ]];
        $payload['checkout_token'] = $this->postJson('/api/v1/checkout/preview', $payload)
            ->assertOk()->json('data.checkout_token');

        return ['user_id' => $customer->id, 'payload' => $payload, 'variant' => $variant];
    }

    private function waitForFile(string $path, Process $process, float $timeout = 4): array
    {
        $deadline = microtime(true) + $timeout;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function worker(array $fixture, string $directory, array $options): Process
    {
        unset($fixture['variant']);
        $process = new Process([PHP_BINARY, __DIR__.'/checkout-worker.php'], base_path(), timeout: 15);
        $process->setInput(json_encode([...$fixture, 'directory' => $directory, ...$options], JSON_THROW_ON_ERROR));
        $process->start();

        return $process;
    }

    #[DataProvider('scenarios')]
    public function test_two_real_checkout_processes(string $scenario): void
    {
        $codesA = $scenario === 'unused_overlap' ? ['W1', 'W2'] : ['W1'];
        $codesB = match ($scenario) {
            'unused_overlap' => ['W2', 'W3'],
            'disjoint' => ['W3'],
            default => ['W1'],
        };
        $a = $this->prepareCustomer('AUDIT-A', $codesA, 107.6);
        if ($scenario === 'invalid_token') {
            $a['payload']['checkout_token'] = 'invalid-audit-token';
        }
        $b = $this->prepareCustomer('AUDIT-B', $codesB, in_array($scenario, ['unused_overlap', 'disjoint'], true) ? 109.0 : 107.6,
            $scenario === 'same_variant' ? $a['variant'] : null);
        $directory = sys_get_temp_dir().'/walatra-checkout-lock-audit-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/a', 0700);
        mkdir($directory.'/b', 0700);
        $workerA = $this->worker($a, $directory.'/a', ['hold_stock' => true, 'stock_failure' => $scenario === 'upstream_failure']);
        $workerB = null;
        $blocking = null;
        $completedBeforeRelease = false;
        try {
            $readyA = $this->waitForFile($directory.'/a/ready.json', $workerA);
            $this->waitForFile($directory.'/a/entered-http.json', $workerA);
            $workerB = $this->worker($b, $directory.'/b', [
                'hold_stock' => false, 'selection_timeout' => $scenario === 'shared_warehouse' ? 0.1 : 5,
                ...($scenario === 'preview' ? ['operation' => 'preview'] : []),
                ...($scenario === 'lock_timeout' ? ['lock_timeout_ms' => 200] : []),
            ]);
            $readyB = $this->waitForFile($directory.'/b/ready.json', $workerB);
            if (in_array($scenario, ['disjoint', 'preview'], true)) {
                $resultB = $this->waitForFile($directory.'/b/result.json', $workerB);
                $completedBeforeRelease = true;
                $this->assertTrue($workerA->isRunning());
            } else {
                $deadline = microtime(true) + 2;
                do {
                    $activity = DB::selectOne('select wait_event_type, wait_event, query, pg_blocking_pids(pid)::text as blockers from pg_stat_activity where pid = ?', [$readyB['pid']]);
                    if ($activity?->wait_event_type === 'Lock') {
                        $blocking = (array) $activity;
                        break;
                    }
                    usleep(10000);
                } while ($workerB->isRunning() && microtime(true) < $deadline);
                $this->assertNotNull($blocking, $workerB->getOutput().$workerB->getErrorOutput());
                $this->assertStringContainsString((string) $readyA['pid'], $blocking['blockers']);
                $this->assertStringContainsString($scenario === 'same_variant' ? '"product_variants"' : '"warehouses"', $blocking['query']);
                $this->assertFileDoesNotExist($directory.'/b/entered-http.json');
                if ($scenario === 'lock_timeout') {
                    $resultB = $this->waitForFile($directory.'/b/result.json', $workerB);
                } else {
                    usleep(350000);
                }
            }
            file_put_contents($directory.'/a/release-stock', 'release');
            $resultA = $this->waitForFile($directory.'/a/result.json', $workerA);
            $resultB = $this->waitForFile($directory.'/b/result.json', $workerB);
            foreach ([$workerA, $workerB] as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $this->assertSame(match ($scenario) { 'upstream_failure' => 503, 'invalid_token' => 422, default => 201 }, $resultA['status']);
            $this->assertSame(match ($scenario) { 'preview' => 200, 'lock_timeout' => 500, default => 201 }, $resultB['status']);
            $this->assertSame(0, $resultA['transactions_after_request']);
            $this->assertSame(0, $resultB['transactions_after_request']);
            $failedA = in_array($scenario, ['upstream_failure', 'invalid_token'], true);
            $this->assertSame($failedA ? 0 : 1, $resultA['orders_for_user']);
            $this->assertSame(in_array($scenario, ['preview', 'lock_timeout'], true) ? 0 : 1, $resultB['orders_for_user']);
            $this->assertSame($failedA ? 1 : 0, $resultA['cart_items_for_user']);
            $this->assertSame(in_array($scenario, ['preview', 'lock_timeout'], true) ? 1 : 0, $resultB['cart_items_for_user']);
            if ($scenario === 'shared_warehouse') {
                $this->assertGreaterThan(300, $resultB['first_http_delay_ms']);
                $warehouseQuery = collect($resultB['lock_queries'])->firstWhere('table', 'warehouses');
                $this->assertGreaterThan(300, $warehouseQuery['duration_ms']);
                $this->assertSame(100.0, (float) $resultB['selection_timeout_ms']);
            }
            if ($scenario === 'unused_overlap') {
                $this->assertSame('W1', $resultA['warehouse']);
                $this->assertSame('W3', $resultB['warehouse']);
            }
            if ($scenario === 'lock_timeout') {
                $this->assertSame('55P03', $resultB['reported_database_errors'][0]['sqlstate']);
                $this->assertNull($resultB['first_http_delay_ms']);
            }
            $orders = Order::with('items')->get();
            $quantity = $orders->sum(fn (Order $order) => $order->items->sum('quantity'));
            if ($scenario === 'same_variant') {
                $this->assertCount(2, $orders);
                $this->assertSame(2, $quantity);
                foreach ($orders as $order) {
                    $this->assertNull($order->stock_reservation_id);
                    $this->assertFalse($order->stock_check['reserved']);
                }
            }
            $summary = [
                'scenario' => $scenario, 'blocking' => $blocking,
                'candidate_codes' => ['a' => $codesA, 'b' => $codesB],
                'b_completed_before_a_stock_release' => $completedBeforeRelease,
                'a' => $resultA, 'b' => $resultB,
                'upstream_reported_quantity_per_call' => 1,
                'total_ordered_quantity' => $quantity,
                'order_reservations_present' => $orders->map(fn (Order $order) => $order->stock_reservation_id !== null)->all(),
            ];
            file_put_contents(__DIR__.'/evidence/'.$scenario.'.json', json_encode($summary, JSON_PRETTY_PRINT).PHP_EOL);
            fwrite(STDOUT, "\n".json_encode(['scenario' => $scenario, 'statuses' => [$resultA['status'], $resultB['status']],
                'b_elapsed_ms' => $resultB['elapsed_ms'], 'b_first_http_delay_ms' => $resultB['first_http_delay_ms']])."\n");
        } finally {
            file_put_contents($directory.'/a/release-stock', 'release');
            foreach ([$workerA, $workerB] as $worker) {
                if ($worker?->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*/*') as $file) {
                unlink($file);
            }
            rmdir($directory.'/a');
            rmdir($directory.'/b');
            rmdir($directory);
        }
    }

    public function test_warehouse_sync_can_deadlock_with_checkout_when_upstream_order_is_reversed(): void
    {
        $customer = $this->prepareCustomer('DEADLOCK', ['W1', 'W2'], 107.6);
        $directory = sys_get_temp_dir().'/walatra-checkout-lock-audit-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/a', 0700);
        mkdir($directory.'/b', 0700);
        $sync = new Process([PHP_BINARY, __DIR__.'/warehouse-sync-worker.php'], base_path(), timeout: 15);
        $sync->setInput(json_encode(['directory' => $directory.'/a'], JSON_THROW_ON_ERROR));
        $sync->start();
        $checkout = null;
        try {
            $readyA = $this->waitForFile($directory.'/a/ready.json', $sync);
            $this->waitForFile($directory.'/a/held-w2.json', $sync);
            $checkout = $this->worker($customer, $directory.'/b', ['hold_stock' => false]);
            $readyB = $this->waitForFile($directory.'/b/ready.json', $checkout);
            $blocking = null;
            $deadline = microtime(true) + 2;
            do {
                $activity = DB::selectOne('select wait_event_type, query, pg_blocking_pids(pid)::text as blockers from pg_stat_activity where pid = ?', [$readyB['pid']]);
                if ($activity?->wait_event_type === 'Lock') {
                    $blocking = (array) $activity;
                    break;
                }
                usleep(10000);
            } while ($checkout->isRunning() && microtime(true) < $deadline);
            $this->assertNotNull($blocking);
            $this->assertStringContainsString('"warehouses"', $blocking['query']);
            $this->assertStringContainsString((string) $readyA['pid'], $blocking['blockers']);
            file_put_contents($directory.'/a/release-sync', 'release');
            $resultA = $this->waitForFile($directory.'/a/result.json', $sync, 6);
            $resultB = $this->waitForFile($directory.'/b/result.json', $checkout, 6);
            foreach ([$sync, $checkout] as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $states = array_merge($resultA['sqlstates'], array_column($resultB['reported_database_errors'], 'sqlstate'));
            $this->assertContains('40P01', $states);
            $this->assertSame(1, count(array_filter($states, fn ($state) => $state === '40P01')));
            $this->assertTrue(($resultA['status'] === 500 && $resultB['status'] === 201)
                || ($resultA['status'] === 200 && $resultB['status'] === 500));
            $this->assertSame(0, $resultA['transactions_after_request']);
            $this->assertSame(0, $resultB['transactions_after_request']);
            $summary = ['scenario' => 'sync_deadlock', 'blocking_before_cycle' => $blocking, 'sync' => $resultA, 'checkout' => $resultB];
            file_put_contents(__DIR__.'/evidence/sync_deadlock.json', json_encode($summary, JSON_PRETTY_PRINT).PHP_EOL);
            fwrite(STDOUT, "\n".json_encode(['scenario' => 'sync_deadlock', 'sqlstates' => $states,
                'sync_status' => $resultA['status'], 'checkout_status' => $resultB['status']])."\n");
        } finally {
            file_put_contents($directory.'/a/release-sync', 'release');
            foreach ([$sync, $checkout] as $worker) {
                if ($worker?->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*/*') as $file) {
                unlink($file);
            }
            rmdir($directory.'/a');
            rmdir($directory.'/b');
            rmdir($directory);
        }
    }
}
