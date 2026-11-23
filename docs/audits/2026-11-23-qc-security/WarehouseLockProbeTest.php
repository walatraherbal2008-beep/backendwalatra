<?php

namespace Tests\Audit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseLockProbeTest extends TestCase
{
    public function test_checkout_holds_all_candidate_warehouse_locks_during_external_stock_request(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'walatra_cart_concurrency_test_')) {
            $this->markTestSkipped('Requires an isolated PostgreSQL cart test database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config([
            'checkout.mode' => 'manual', 'checkout.free_shipping' => true,
            'checkout.stock_mode' => 'check_only', 'checkout.warehouse_selection' => 'nearest_available',
            'services.warehouse_remaining_stock.enabled' => true,
            'services.warehouse_remaining_stock.base_url' => 'https://stock.example.test',
        ]);
        Http::preventStrayRequests();
        foreach (['NEAR' => 107.6, 'FAR' => 108.2] as $code => $longitude) {
            Warehouse::create([
                'code' => $code, 'realname' => $code, 'codename' => $code, 'initial' => $code,
                'area_group' => 'pulaujawa', 'latitude' => -6.9, 'longitude' => $longitude,
                'is_active' => true, 'is_listed' => true, 'synced_at' => now(),
            ]);
        }
        $customer = User::factory()->create();
        Sanctum::actingAs($customer, ['customer-api']);
        $product = Product::factory()->create(['product_code' => 'LOCK-PROBE']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        foreach (['NEAR', 'FAR'] as $code) {
            $variant->warehouseAssignments()->create(['warehouse_code' => $code]);
        }
        $listing = StoreProductListing::factory()->create(['product_id' => $product->id]);
        $this->postJson('/api/v1/cart/items', [
            'listing_id' => $listing->id, 'variant_id' => $variant->id, 'quantity' => 1,
        ])->assertOk();
        $payload = ['shipping_address' => [
            'name' => 'Audit Lock', 'phone' => '628123456789', 'address' => 'Jalan Pengujian',
            'province' => 'Jawa Barat', 'city' => 'Bandung', 'postal_code' => '40135',
            'latitude' => -6.9, 'longitude' => 107.6,
        ]];
        Http::fake(['*' => Http::response(['error' => '', 'data' => ['jumlah' => 100]])]);
        $payload['checkout_token'] = $this->postJson('/api/v1/checkout/preview', $payload)
            ->assertOk()->json('data.checkout_token');
        $independent = DB::build(config('database.connections.pgsql'));
        $independent->statement("SET lock_timeout = '100ms'");
        $states = [];
        $transactionDepth = null;
        Http::fake(function () use ($independent, &$states, &$transactionDepth) {
            $transactionDepth = DB::transactionLevel();
            foreach (['NEAR', 'FAR'] as $code) {
                try {
                    $independent->select('select code from warehouses where code = ? for update', [$code]);
                    $states[$code] = 'not_locked';
                } catch (QueryException $exception) {
                    $states[$code] = (string) $exception->getCode();
                }
            }

            return Http::response(['error' => '', 'data' => ['jumlah' => 100]]);
        });
        try {
            $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'warehouse-lock-probe'])
                ->assertCreated()->assertJsonPath('data.shipping_origin.warehouse_code', 'NEAR');
            $this->assertGreaterThan(0, $transactionDepth);
            $this->assertSame(['NEAR' => '55P03', 'FAR' => '55P03'], $states);
            fwrite(STDOUT, "\n".json_encode([
                'probe' => 'shared_warehouse_lock', 'selected' => 'NEAR',
                'transaction_depth_during_http' => $transactionDepth,
                'independent_connection_lock_results' => $states,
                'lock_timeout_ms' => 100,
            ])."\n");
        } finally {
            $independent->disconnect();
        }
    }
}
