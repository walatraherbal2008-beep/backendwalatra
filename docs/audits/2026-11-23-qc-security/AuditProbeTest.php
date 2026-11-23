<?php

namespace Tests\Audit;

use App\Models\ApiRequestAuditLog;
use App\Models\ProductVariant;
use App\Models\StoreProductListing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Historical defect probes remain until fixed; the export status probe now checks the regression.
class AuditProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.debug' => false]);
    }

    private function admin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 'active']);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function evidence(string $name, array $data): void
    {
        fwrite(STDOUT, "\n".json_encode(['probe' => $name, ...$data], JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_customer_supplied_formula_survives_csv_export(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer, ['customer-api']);
        $this->patchJson('/api/v1/auth/customer/profile', ['name' => '=1+1'])->assertOk();
        $admin = $this->admin();
        $response = $this->actingAs($admin, 'web')->get('/superadmin/customers/export')->assertOk();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));
        $row = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === (string) $customer->id);
        $this->assertSame('=1+1', $row[1]);
        $this->evidence('csv_formula', ['profile_status' => 200, 'export_status' => 200, 'exported_name' => $row[1]]);
    }

    public function test_existing_session_cannot_export_after_admin_is_blocked(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');
        $admin->update(['status' => 'blocked']);
        $this->assertFalse($admin->fresh()->canAccessPanel(Filament::getPanel('superadmin')));
        $this->get('/superadmin/customers/export')->assertForbidden();
        $this->evidence('blocked_admin_export', ['can_access_panel' => false, 'export_status' => 403]);
    }

    public function test_non_numeric_route_parameters_produce_server_errors(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer-api']);
        $statuses = [];
        foreach ([
            ['GET', '/api/v1/orders/not-a-number', []],
            ['GET', '/api/v1/orders/not-a-number/payment', []],
            ['POST', '/api/v1/orders/not-a-number/cancel', []],
            ['PATCH', '/api/v1/cart/items/not-a-number', ['quantity' => 1]],
        ] as [$method, $path, $payload]) {
            $response = $this->json($method, $path, $payload);
            $statuses[$method.' '.$path] = $response->status();
        }
        $this->assertSame([500, 500, 500, 500], array_values($statuses));
        $this->withoutExceptionHandling();
        try {
            $this->getJson('/api/v1/orders/not-a-number');
            $this->fail('Expected a TypeError from the int route argument.');
        } catch (\TypeError $exception) {
            $this->assertStringContainsString('OrderController::show()', $exception->getMessage());
        }
        $this->evidence('invalid_route_ids', ['statuses' => $statuses, 'exception_type' => 'TypeError']);
    }

    public function test_unmatched_public_api_requests_write_audit_rows_without_route_throttle(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/audit-probe-missing-'.$i)->assertNotFound();
        }
        $queries = collect(DB::getQueryLog());
        $inserts = $queries->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert into "api_request_audit_logs"'))->count();
        $updates = $queries->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update "api_request_audit_logs"'))->count();
        DB::disableQueryLog();
        $this->assertSame(10, ApiRequestAuditLog::count());
        $this->assertSame(10, $inserts);
        $this->assertSame(10, $updates);
        $this->evidence('unmatched_request_audit_writes', ['requests' => 10, 'status' => 404, 'inserts' => $inserts, 'updates' => $updates]);
    }

    public function test_product_catalog_query_count_at_two_page_sizes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $variant = ProductVariant::factory()->create();
            StoreProductListing::factory()->create(['product_id' => $variant->product_id]);
        }
        $samples = [];
        DB::enableQueryLog();
        try {
            foreach ([5, 25] as $pageSize) {
                DB::flushQueryLog();
                $started = hrtime(true);
                $response = $this->getJson('/api/v1/catalog/products?per_page='.$pageSize)->assertOk();
                $elapsed = (hrtime(true) - $started) / 1e6;
                $count = count(DB::getQueryLog());
                $this->assertCount($pageSize, $response->json('data'));
                $this->assertLessThanOrEqual(15, $count);
                $samples[] = ['page_size' => $pageSize, 'queries' => $count, 'elapsed_ms' => round($elapsed, 2)];
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->evidence('catalog_query_scaling', ['database' => 'sqlite-memory', 'samples' => $samples]);
    }
}
