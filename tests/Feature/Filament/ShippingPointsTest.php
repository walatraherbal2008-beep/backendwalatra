<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ShippingPoints;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShippingPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('superadmin'));
        config([
            'services.warehouse_directory.enabled' => true,
            'services.warehouse_directory.base_url' => 'https://warehouse.example.test',
            'services.warehouse_directory.path' => '/index.php?procs=gudang.list',
            'services.warehouse_directory.detail_path' => '/index.php?procs=gudang.detail',
        ]);
        Http::preventStrayRequests();
    }

    private function admin(string $role = 'super_admin', string $status = 'active'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => $status, 'email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function rows(): array
    {
        return [
            ['code' => 'WH-BDG', 'initial' => 'BG', 'codename' => 'BANDUNG', 'realname' => 'Bandung', 'area_group' => 'pulaujawa'],
            ['code' => 'WH-ACEH', 'initial' => 'AC', 'codename' => 'ACEH', 'realname' => 'Aceh', 'area_group' => 'luarjawa'],
        ];
    }

    private function fakeDirectory(array $detail = []): void
    {
        Http::fake([
            '*/index.php?procs=gudang.list' => Http::response(['error' => '', 'data' => $this->rows()]),
            '*/index.php?procs=gudang.detail' => Http::response(['error' => '', 'data' => [
                'codename' => 'BANDUNG', 'area_group' => 'pulaujawa', 'initial' => 'BG', 'realname' => 'Bandung',
                'fulladdr' => 'Jalan Parakan Saat, Bandung', 'coordinates' => '', 'postal_code' => '', ...$detail,
            ]]),
        ]);
    }

    public function test_superadmin_sees_shipping_point_navigation_and_read_only_table(): void
    {
        $this->admin();
        $this->fakeDirectory();
        $this->get(ShippingPoints::getUrl())->assertOk()->assertSee('Titik Pengiriman');
        Livewire::test(ShippingPoints::class)->assertCanSeeTableRecords(['WH-ACEH', 'WH-BDG'], inOrder: true)
            ->assertTableColumnStateSet('realname', 'Bandung', 'WH-BDG')
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
    }

    public function test_shipping_points_can_be_searched_and_filtered(): void
    {
        $this->admin();
        $this->fakeDirectory();
        Livewire::test(ShippingPoints::class)->searchTable('bAnDuNg')
            ->assertCanSeeTableRecords(['WH-BDG'])->assertCanNotSeeTableRecords(['WH-ACEH'])
            ->searchTable('')->filterTable('area_group', 'luarjawa')
            ->assertCanSeeTableRecords(['WH-ACEH'])->assertCanNotSeeTableRecords(['WH-BDG']);
    }

    public function test_detail_uses_post_form_and_shows_missing_location_fields(): void
    {
        $this->admin();
        $this->fakeDirectory();
        Livewire::test(ShippingPoints::class)->mountTableAction('viewDetails', 'WH-BDG')
            ->assertTableActionDataSet(['code' => 'WH-BDG', 'fulladdr' => 'Jalan Parakan Saat, Bandung', 'map_url' => null])
            ->assertSchemaComponentStateSet('fulladdr', 'Jalan Parakan Saat, Bandung', schema: 'mountedActionSchema0')
            ->assertSchemaComponentStateSet('coordinates', null, schema: 'mountedActionSchema0');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'gudang.detail')
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request['code'] === 'WH-BDG');
    }

    public function test_valid_coordinates_provide_google_maps_link(): void
    {
        $this->admin();
        $this->fakeDirectory(['coordinates' => '-6.91, 107.63', 'postal_code' => '40291']);
        Livewire::test(ShippingPoints::class)->mountTableAction('viewDetails', 'WH-BDG')
            ->assertTableActionDataSet(['map_url' => 'https://www.google.com/maps/search/?api=1&query=-6.91%2C107.63'])
            ->assertSchemaComponentStateSet('map_url', 'https://www.google.com/maps/search/?api=1&query=-6.91%2C107.63', schema: 'mountedActionSchema0');
    }

    public function test_invalid_coordinates_do_not_create_a_map_link(): void
    {
        $this->admin();
        $this->fakeDirectory(['coordinates' => '999, 999']);
        Livewire::test(ShippingPoints::class)->mountTableAction('viewDetails', 'WH-BDG')
            ->assertTableActionDataSet(['map_url' => null])->assertDontSee('Buka di Google Maps');
    }

    public function test_empty_detail_response_is_not_shown_as_a_successful_detail(): void
    {
        $this->admin();
        $this->fakeDirectory(['codename' => '', 'realname' => '']);
        Livewire::test(ShippingPoints::class)->mountTableAction('viewDetails', 'WH-BDG')
            ->assertNotified('Detail titik pengiriman belum dapat dimuat');
    }

    public function test_list_service_failure_can_be_retried_using_reload(): void
    {
        $this->admin();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1 ? Http::response([], 503)
                : Http::response(['error' => '', 'data' => $this->rows()]);
        });
        Livewire::test(ShippingPoints::class)->assertSee('Titik pengiriman belum dapat dimuat')
            ->callAction('reload')->assertCanSeeTableRecords(['WH-ACEH', 'WH-BDG']);
    }

    public function test_customer_store_admin_and_inactive_superadmin_cannot_access_page(): void
    {
        foreach ([['store_admin', 'active'], ['super_admin', 'inactive']] as [$role, $status]) {
            $this->admin($role, $status);
            $this->assertFalse(ShippingPoints::canAccess());
            $this->get('/superadmin/titik-pengiriman')->assertForbidden();
        }
        $this->actingAs(User::factory()->create());
        $this->assertFalse(ShippingPoints::canAccess());
        $this->get('/superadmin/titik-pengiriman')->assertForbidden();
    }

    public function test_sync_action_publishes_local_directory_and_details_work_without_external_calls(): void
    {
        $this->admin();
        $this->fakeDirectory(['coordinates' => '-6.91, 107.63']);
        Livewire::test(ShippingPoints::class)->callAction('syncWarehouses')
            ->assertNotified('Master gudang disinkronkan')->assertCanSeeTableRecords(['WH-ACEH', 'WH-BDG']);
        $this->assertDatabaseCount('warehouses', 2);
        $count = Http::recorded()->count();
        Livewire::test(ShippingPoints::class)->mountTableAction('viewDetails', 'WH-BDG')
            ->assertTableActionDataSet(['code' => 'WH-BDG', 'coordinates' => '-6.91, 107.63']);
        $this->assertSame($count, Http::recorded()->count());
    }

    public function test_local_active_setting_survives_sync_and_is_reflected_in_table(): void
    {
        $this->admin();
        $this->fakeDirectory();
        Livewire::test(ShippingPoints::class)->callAction('syncWarehouses')
            ->callTableAction('toggleActive', 'WH-BDG')->assertTableColumnStateSet('is_active', false, 'WH-BDG')
            ->callAction('syncWarehouses')->assertTableColumnStateSet('is_active', false, 'WH-BDG');
        $this->assertFalse(Warehouse::where('code', 'WH-BDG')->sole()->is_active);
    }

    public function test_nearest_mode_with_empty_local_registry_does_not_fetch_directory_on_page_load(): void
    {
        $this->admin();
        config(['checkout.warehouse_selection' => 'nearest_available']);
        Livewire::test(ShippingPoints::class)->assertSee('Tidak ada titik pengiriman');
        Http::assertNothingSent();
    }

    public function test_failed_manual_sync_preserves_existing_local_records(): void
    {
        $this->admin();
        Warehouse::create(['code' => 'WH-LOCAL', 'realname' => 'Lokal', 'codename' => 'LOCAL', 'initial' => 'LC',
            'area_group' => 'pulaujawa', 'synced_at' => now()]);
        Http::fake(['*' => Http::response([], 503)]);
        Livewire::test(ShippingPoints::class)->callAction('syncWarehouses')
            ->assertNotified('Sinkronisasi gudang gagal')->assertCanSeeTableRecords(['WH-LOCAL']);
        $this->assertDatabaseCount('warehouses', 1);
    }
}
