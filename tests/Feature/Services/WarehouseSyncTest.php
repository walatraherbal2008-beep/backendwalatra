<?php

namespace Tests\Feature\Services;

use App\Contracts\WarehouseDirectoryProvider;
use App\Exceptions\WarehouseDirectoryUnavailableException;
use App\Models\Warehouse;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use App\Services\Warehouse\WarehouseSyncService;
use App\Support\GeoCoordinates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WarehouseSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSource(array $codes, array $coordinates = []): void
    {
        $directory = Mockery::mock(WarehouseDirectoryProvider::class);
        $directory->shouldReceive('list')->once()->andReturn(array_map(fn (string $code): array => [
            'code' => $code, 'realname' => 'Gudang '.$code, 'codename' => $code, 'initial' => 'WH', 'area_group' => 'pulaujawa',
        ], $codes));
        $client = Mockery::mock(ExternalWarehouseDirectoryClient::class);
        $client->shouldReceive('detail')->andReturnUsing(function (string $code) use ($coordinates): array {
            if (($coordinates[$code] ?? null) === 'FAIL') {
                throw new WarehouseDirectoryUnavailableException('Detail tidak tersedia');
            }

            return ['coordinates' => $coordinates[$code] ?? '-6.9, 107.6', 'fulladdr' => 'Alamat '.$code, 'postal_code' => '40135'];
        });
        $this->app->instance(WarehouseDirectoryProvider::class, $directory);
        $this->app->instance(ExternalWarehouseDirectoryClient::class, $client);
    }

    private function existing(): Warehouse
    {
        return Warehouse::create(['code' => 'OLD', 'realname' => 'Lama', 'codename' => 'OLD', 'initial' => 'WH',
            'area_group' => 'pulaujawa', 'latitude' => -6.9, 'longitude' => 107.6, 'is_active' => false, 'synced_at' => now()->subDay()]);
    }

    public function test_complete_sync_upserts_coordinates_preserves_local_disable_and_marks_missing_records_unlisted(): void
    {
        $old = $this->existing();
        $this->fakeSource(['OLD', '5504027954349'], ['5504027954349' => '-7.366010843848928, 108.22806304350148']);
        $this->artisan('warehouses:sync')->assertSuccessful();
        $this->assertDatabaseCount('warehouses', 2);
        $this->assertFalse($old->fresh()->is_active);
        $warehouse = Warehouse::where('code', '5504027954349')->sole();
        $this->assertSame('5504027954349', $warehouse->code);
        $this->assertEqualsWithDelta(-7.3660108, (float) $warehouse->latitude, 0.0000001);
        $this->assertTrue($warehouse->is_listed);

        $this->fakeSource(['5504027954349']);
        $this->artisan('warehouses:sync')->assertSuccessful();
        $this->assertFalse($old->fresh()->is_listed);
        $this->assertDatabaseCount('warehouses', 2);
    }

    public function test_failed_detail_does_not_partially_publish_or_hide_any_warehouse(): void
    {
        $old = $this->existing();
        $snapshot = $old->fresh()->getAttributes();
        $this->fakeSource(['NEW-1', 'NEW-2'], ['NEW-2' => 'FAIL']);
        $this->artisan('warehouses:sync')->assertFailed();
        $this->assertDatabaseCount('warehouses', 1);
        $this->assertSame($snapshot, $old->fresh()->getAttributes());
    }

    public function test_empty_or_duplicate_list_cannot_replace_last_good_snapshot(): void
    {
        $old = $this->existing();
        foreach ([[], ['NEW', 'NEW']] as $codes) {
            $this->fakeSource($codes);
            $this->artisan('warehouses:sync')->assertFailed();
            $this->assertDatabaseCount('warehouses', 1);
            $this->assertTrue($old->fresh()->is_listed);
        }
    }

    public function test_invalid_coordinates_are_stored_for_review_but_not_usable_for_distance(): void
    {
        $this->fakeSource(['BAD', 'EMPTY'], ['BAD' => '999, 108', 'EMPTY' => '']);
        $this->assertSame(['synced' => 2, 'without_coordinates' => 2], app(WarehouseSyncService::class)->sync());
        foreach (Warehouse::all() as $warehouse) {
            $this->assertNull($warehouse->latitude);
            $this->assertNull($warehouse->longitude);
        }
        $this->assertSame('999, 108', Warehouse::where('code', 'BAD')->sole()->coordinates);
    }

    public function test_overlapping_sync_is_rejected_without_contacting_provider(): void
    {
        Http::preventStrayRequests();
        $lock = Cache::lock('warehouses:sync', 600);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('warehouses:sync')->assertFailed();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_distance_handles_identical_opposite_and_zero_coordinates(): void
    {
        $this->assertTrue(GeoCoordinates::valid(0, 0));
        $this->assertNull(GeoCoordinates::parse('https://maps.example.test'));
        $this->assertEqualsWithDelta(0, GeoCoordinates::distanceKm(0, 0, 0, 0), 0.000001);
        $this->assertEqualsWithDelta(111.195, GeoCoordinates::distanceKm(0, 0, 0, 1), 0.001);
        $this->assertEqualsWithDelta(20015.114, GeoCoordinates::distanceKm(0, 0, 0, 180), 0.001);
    }
}
