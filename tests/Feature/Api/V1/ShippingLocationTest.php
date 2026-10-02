<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShippingLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.everpro.enabled' => true,
            'services.everpro.client_key' => 'client-key-test',
            'services.everpro.client_secret' => 'client-secret-test',
            'services.everpro.base_url' => 'https://client-api-sandbox.everpro.id',
        ]);
    }

    public function test_public_province_endpoint_gets_everpro_token_and_locations(): void
    {
        Http::fake([
            '*/auth/v1/token' => Http::response([
                'data' => ['access_token' => 'everpro-token'],
            ]),
            '*/location/v1/provinces*' => Http::response([
                'data' => [
                    'provinces' => [
                        ['id' => '32', 'name' => 'Jawa Barat'],
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/v1/shipping/locations/provinces?q=Jawa')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.locations.0.id', '32')
            ->assertJsonPath('data.locations.0.name', 'Jawa Barat');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://client-api-sandbox.everpro.id/location/v1/provinces?province_name=Jawa'
            && $request->header('Authorization') === ['Bearer everpro-token']);
    }

    public function test_public_location_endpoints_validate_hierarchy_parameters(): void
    {
        Http::fake([
            '*/auth/v1/token' => Http::response([
                'data' => ['access_token' => 'everpro-token'],
            ]),
            '*' => Http::response(['data' => ['sub_districts' => []]]),
        ]);

        $this->getJson('/api/v1/shipping/locations/cities')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->getJson('/api/v1/shipping/locations/sub-districts?postal_code=40135')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.locations', []);
    }

    public function test_resolve_endpoint_sends_address_or_postal_code_to_everpro(): void
    {
        Http::fake([
            '*/auth/v1/token' => Http::response([
                'data' => ['access_token' => 'everpro-token'],
            ]),
            '*/location/v1/postalcode' => Http::response([
                'data' => [
                    'province_id' => '32',
                    'city_id' => '3273',
                    'district_id' => '327301',
                    'sub_district_id' => '3273011001',
                    'postal_code' => '40135',
                ],
            ]),
        ]);

        $this->postJson('/api/v1/shipping/locations/resolve', [
            'postal_code' => '40135',
        ])->assertOk()
            ->assertJsonPath('data.location.sub_district_id', '3273011001');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://client-api-sandbox.everpro.id/location/v1/postalcode'
            && $request['postal_code'] === '40135'
            && $request->header('Authorization') === ['Bearer everpro-token']);
    }
}
