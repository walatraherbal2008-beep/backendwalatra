<?php

namespace Tests\Unit;

use App\Services\Shipping\ShippingApiRateProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingApiRateProviderTest extends TestCase
{
    public function test_provider_maps_shipping_quote(): void
    {
        config([
            'services.shipping.base_url' => 'https://shipping.test',
            'services.shipping.token' => 'shipping-token',
        ]);

        Http::fake([
            'https://shipping.test/shipping/quote' => Http::response([
                'data' => [
                    'fee' => 12000,
                    'service' => 'REG',
                    'eta_days' => 3,
                ],
            ]),
        ]);

        $quote = (new ShippingApiRateProvider)->quote(
            ['city' => 'Bandung'],
            [['sku' => 'SKU-1', 'quantity' => 1]],
        );

        $this->assertSame(12000.0, $quote['fee']);
        $this->assertSame('REG', $quote['service']);
        $this->assertSame(3, $quote['eta_days']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer shipping-token'));
    }
}
