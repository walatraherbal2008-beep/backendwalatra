<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\WarehouseUnavailableException;
use App\Models\ExternalApiActivityLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ExternalApiHealthChecker;
use App\Services\Warehouse\WarehouseRemainingStockClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseRemainingStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.warehouse_remaining_stock.enabled' => true, 'services.warehouse_remaining_stock.base_url' => 'https://stock.example.test']);
        Http::preventStrayRequests();
        Warehouse::create(['code' => '5504027954349', 'realname' => 'Tasikmalaya', 'codename' => 'TASIK', 'initial' => 'TS',
            'area_group' => 'pulaujawa', 'synced_at' => now()]);
        Product::factory()->create(['product_code' => 'QNC']);
    }

    private function authenticate(string $status = 'active', array $abilities = ['customer-api']): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => $status]), $abilities);
    }

    private function stock(array $payload = [])
    {
        return $this->postJson('/api/v1/warehouses/5504027954349/stock', $payload ?: ['product_code' => 'QNC', 'quantity' => 2]);
    }

    public function test_endpoint_sends_multipart_product_code_and_preserves_zero_stock(): void
    {
        $this->authenticate();
        Http::fake(['*' => Http::response(['error' => '', 'data' => ['jumlah' => 0]])]);
        $this->stock()->assertOk()->assertJsonPath('data.available_quantity', 0)
            ->assertJsonPath('data.sufficient', false)->assertJsonPath('data.reserved', false);
        Http::assertSent(function ($request): bool {
            $form = collect($request->data())->pluck('contents', 'name')->all();

            return $request->method() === 'POST' && $request->url() === 'https://stock.example.test/index.php?procs=gudang.stok.sisa'
                && str_contains($request->header('Content-Type')[0], 'multipart/form-data')
                && $form === ['code' => '5504027954349', 'produk' => 'QNC'];
        });
        $this->assertSame('succeeded', ExternalApiActivityLog::sole()->status);
        $this->assertSame('warehouse_remaining_stock', ExternalApiActivityLog::sole()->service);
    }

    public function test_endpoint_accepts_integer_string_stock_and_exactly_sufficient_quantity(): void
    {
        $this->authenticate();
        Http::fake(['*' => Http::response(['error' => '', 'data' => ['jumlah' => '2']])]);
        $this->stock()->assertOk()->assertJsonPath('data.available_quantity', 2)->assertJsonPath('data.sufficient', true);
    }

    public static function invalidResponses(): array
    {
        return [
            'provider error' => [['error' => 'failed', 'data' => ['jumlah' => 0]], 200],
            'missing error field' => [['data' => ['jumlah' => 4]], 200],
            'missing quantity' => [['error' => '', 'data' => []], 200],
            'fraction' => [['error' => '', 'data' => ['jumlah' => 1.5]], 200],
            'boolean' => [['error' => '', 'data' => ['jumlah' => true]], 200],
            'overflow' => [['error' => '', 'data' => ['jumlah' => '9999999999999999999999']], 200],
            'HTML' => ['<html>Provider unavailable</html>', 200],
            'server failure' => [['error' => '', 'data' => ['jumlah' => 10]], 500],
            'redirect' => [['error' => '', 'data' => ['jumlah' => 10]], 302],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_provider_response_is_not_reported_as_empty_stock(mixed $payload, int $status): void
    {
        $this->authenticate();
        Http::fake(['*' => Http::response($payload, $status)]);
        $this->stock()->assertStatus(503)->assertJsonPath('code', 'WAREHOUSE_UNAVAILABLE');
        $this->assertSame('failed', ExternalApiActivityLog::sole()->status);
    }

    public function test_connection_failure_is_unavailable_not_zero(): void
    {
        $this->authenticate();
        Http::fake(['*' => Http::failedConnection()]);
        $this->stock()->assertStatus(503)->assertJsonPath('code', 'WAREHOUSE_UNAVAILABLE');
    }

    public function test_authentication_ability_and_active_status_are_required(): void
    {
        $this->stock()->assertUnauthorized();
        $this->authenticate(abilities: []);
        $this->stock()->assertForbidden();
        $this->authenticate('inactive');
        $this->stock()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unknown_inactive_product_warehouse_and_invalid_request_do_not_contact_provider(): void
    {
        $this->authenticate();
        $this->stock(['product_code' => 'UNKNOWN', 'quantity' => 1])->assertNotFound();
        $this->stock(['product_code' => 'QNC', 'quantity' => 0])->assertUnprocessable();
        Warehouse::query()->update(['is_active' => false]);
        $this->stock()->assertNotFound();
        Warehouse::query()->update(['is_active' => true, 'is_listed' => false]);
        $this->stock()->assertNotFound();
        Warehouse::query()->update(['is_listed' => true]);
        Product::query()->update(['status' => 'inactive']);
        $this->stock()->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_endpoint_is_rate_limited_per_customer(): void
    {
        $this->authenticate();
        Http::fake(['*' => Http::response(['error' => '', 'data' => ['jumlah' => 2]])]);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->stock()->assertOk();
        }
        $this->stock()->assertTooManyRequests();
        Http::assertSentCount(30);
    }

    public function test_expired_deadline_does_not_issue_any_request(): void
    {
        try {
            app(WarehouseRemainingStockClient::class)->quantity('5504027954349', 'QNC', hrtime(true) / 1e9 - 1);
            $this->fail('An expired selection deadline must fail.');
        } catch (WarehouseUnavailableException) {
            Http::assertNothingSent();
        }
    }

    public function test_monitoring_probes_only_connectivity_without_creating_a_transaction(): void
    {
        Http::fake(['*' => Http::response([], 405)]);
        $result = app(ExternalApiHealthChecker::class)->check('warehouse_remaining_stock');
        $this->assertSame('succeeded', $result[0]['status']);
        $this->assertStringContainsString('HEAD', $result[0]['detail']);
        Http::assertSent(fn ($request): bool => $request->method() === 'HEAD'
            && $request->url() === 'https://stock.example.test/index.php?procs=gudang.stok.sisa');
    }
}
