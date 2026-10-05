<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    public function test_public_warehouse_endpoint_maps_external_response(): void
    {
        config([
            'services.warehouse_directory.enabled' => true,
            'services.warehouse_directory.base_url' => 'https://originalwalatraherbal.com',
            'services.warehouse_directory.path' => '/index.php?procs=gudang.list',
        ]);

        Http::fake([
            'https://originalwalatraherbal.com/index.php?procs=gudang.list' => Http::response([
                'error' => '',
                'data' => [
                    [
                        'code' => 'WH-BDG',
                        'initial' => 'BD',
                        'codename' => 'BANDUNG',
                        'realname' => 'Bandung',
                        'area_group' => 'pulaujawa',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/v1/warehouses')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.code', 'WH-BDG')
            ->assertJsonPath('data.0.area_group', 'pulaujawa')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_public_warehouse_group_endpoint_sends_form_data_to_external_api(): void
    {
        config([
            'services.warehouse_directory.enabled' => true,
            'services.warehouse_directory.base_url' => 'https://originalwalatraherbal.com',
            'services.warehouse_directory.path' => '/index.php?procs=gudang.list',
        ]);

        Http::fake([
            'https://originalwalatraherbal.com/index.php?procs=gudang.list' => Http::response([
                'error' => '',
                'data' => [
                    [
                        'code' => 'WH-ACEH',
                        'initial' => 'AC',
                        'codename' => 'ACEH',
                        'realname' => 'ACEH',
                        'area_group' => 'luarjawa',
                    ],
                ],
            ]),
        ]);

        $this->postJson('/api/v1/warehouses/group', ['area_group' => 'luarjawa'])
            ->assertOk()
            ->assertJsonPath('data.0.code', 'WH-ACEH')
            ->assertJsonPath('data.0.area_group', 'luarjawa');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://originalwalatraherbal.com/index.php?procs=gudang.list'
                && str_contains((string) $request->body(), 'area_group')
                && str_contains((string) $request->body(), 'luarjawa');
        });
    }

    public function test_warehouse_group_endpoint_rejects_unknown_area_group(): void
    {
        $this->postJson('/api/v1/warehouses/group', ['area_group' => 'pulau_jawa'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('area_group');
    }
}
