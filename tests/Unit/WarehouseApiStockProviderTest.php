<?php

namespace Tests\Unit;

use App\Services\Warehouse\WarehouseApiStockProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WarehouseApiStockProviderTest extends TestCase
{
    public function test_provider_maps_check_and_reservation_responses(): void
    {
        config([
            'services.warehouse.base_url' => 'https://warehouse.test',
            'services.warehouse.token' => 'warehouse-token',
        ]);

        Http::fake([
            'https://warehouse.test/stock/check' => Http::response([
                'data' => [
                    'available' => true,
                    'items' => [['sku' => 'SKU-1', 'requested' => 2, 'available' => 5]],
                ],
            ]),
            'https://warehouse.test/stock/reserve' => Http::response([
                'data' => ['reservation_id' => 'reservation-1'],
            ]),
            'https://warehouse.test/stock/confirm' => Http::response(['data' => []]),
            'https://warehouse.test/stock/release' => Http::response(['data' => []]),
        ]);

        $provider = new WarehouseApiStockProvider;

        $this->assertTrue($provider->checkAvailability([
            ['sku' => 'SKU-1', 'quantity' => 2],
        ])['available']);
        $this->assertSame('reservation-1', $provider->reserve([
            ['sku' => 'SKU-1', 'quantity' => 2],
        ], 'reference-1'));
        $provider->confirm('reservation-1');
        $provider->release('reservation-1');

        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer warehouse-token'));
    }
}
