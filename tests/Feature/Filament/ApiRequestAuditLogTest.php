<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApiRequestAuditLogs\ApiRequestAuditLogResource;
use App\Filament\Resources\ApiRequestAuditLogs\Pages\ListApiRequestAuditLogs;
use App\Models\ApiRequestAuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiRequestAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
    }

    private function admin(string $role = 'super_admin', string $status = 'active'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function log(array $attributes = []): ApiRequestAuditLog
    {
        return ApiRequestAuditLog::query()->create([
            'request_id' => (string) Str::uuid(),
            'http_method' => 'POST',
            'endpoint' => 'api/v1/orders',
            'route_name' => 'orders.store',
            'status_code' => 201,
            'duration_ms' => 42,
            'authentication_status' => 'authenticated',
            'request_payload' => ['quantity' => 2],
            'response_payload' => ['success' => true],
            'started_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_active_superadmin_can_filter_and_open_request_details(): void
    {
        $this->admin();
        $log = $this->log();
        $other = $this->log(['http_method' => 'GET', 'status_code' => 401, 'endpoint' => 'api/v1/cart']);

        $this->get(ApiRequestAuditLogResource::getUrl())->assertOk()->assertSee('Audit Request API');
        Livewire::test(ListApiRequestAuditLogs::class)
            ->assertCanSeeTableRecords([$log, $other])
            ->filterTable('http_method', 'POST')
            ->assertCanSeeTableRecords([$log])
            ->assertCanNotSeeTableRecords([$other])
            ->mountTableAction('view', $log);

        $this->assertFalse(ApiRequestAuditLogResource::canCreate());
        $this->assertFalse(ApiRequestAuditLogResource::canEdit($log));
        $this->assertFalse(ApiRequestAuditLogResource::canDelete($log));
    }

    public function test_customers_store_admins_and_inactive_superadmins_cannot_access_audit_logs(): void
    {
        foreach ([['customer', 'active'], ['store_admin', 'active'], ['super_admin', 'inactive']] as [$role, $status]) {
            $user = User::factory()->create(['status' => $status]);
            if ($role !== 'customer') {
                $user->assignRole(Role::findOrCreate($role, 'web'));
            }
            $this->actingAs($user);

            $this->assertFalse(ApiRequestAuditLogResource::canAccess());
            $this->get(ApiRequestAuditLogResource::getUrl())->assertForbidden();
            Livewire::test(ListApiRequestAuditLogs::class)->assertForbidden();
        }
    }
}
