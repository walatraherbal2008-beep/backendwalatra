<?php

namespace Tests\Concerns;

use App\Contracts\WarehouseDirectoryProvider;
use App\Services\Warehouse\ExternalWarehouseDirectoryClient;
use Mockery;

trait WithCheckoutWarehouse
{
    protected function setUpWithCheckoutWarehouse(): void
    {
        $directory = Mockery::mock(WarehouseDirectoryProvider::class);
        $directory->shouldReceive('list')->andReturn([
            ['code' => 'WH-TASIK', 'initial' => 'TS', 'codename' => 'TASIKMALAYA', 'realname' => 'Tasikmalaya', 'area_group' => 'pulaujawa'],
        ]);
        $this->app->instance(WarehouseDirectoryProvider::class, $directory);
        $client = Mockery::mock(ExternalWarehouseDirectoryClient::class);
        $client->shouldReceive('detail')->with('WH-TASIK')->andReturn([
            'codename' => 'TASIKMALAYA', 'area_group' => 'pulaujawa', 'initial' => 'TS', 'realname' => 'Tasikmalaya',
            'fulladdr' => 'Jalan Gudang Tasikmalaya', 'coordinates' => '', 'postal_code' => '',
        ]);
        $this->app->instance(ExternalWarehouseDirectoryClient::class, $client);
    }
}
