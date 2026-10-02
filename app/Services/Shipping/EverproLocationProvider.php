<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingLocationProvider;

class EverproLocationProvider implements ShippingLocationProvider
{
    public function __construct(private readonly EverproApiClient $client) {}

    public function provinces(?string $search = null): array
    {
        $response = $this->client->get(config('services.everpro.paths.provinces'), array_filter([
            'province_name' => $search,
        ], fn ($value): bool => filled($value)));

        return $this->locations($response);
    }

    public function cities(?string $provinceId = null, ?string $provinceName = null, ?string $cityName = null): array
    {
        $response = $this->client->get(config('services.everpro.paths.cities'), array_filter([
            'province_id' => $provinceId,
            'province_name' => $provinceName,
            'city_name' => $cityName,
        ], fn ($value): bool => filled($value)));

        return $this->locations($response);
    }

    public function districts(?string $cityId = null, ?string $districtName = null): array
    {
        $response = $this->client->get(config('services.everpro.paths.districts'), array_filter([
            'city_id' => $cityId,
            'district_name' => $districtName,
        ], fn ($value): bool => filled($value)));

        return $this->locations($response);
    }

    public function subDistricts(?string $districtId = null, ?string $name = null, ?string $postalCode = null): array
    {
        $response = $this->client->get(config('services.everpro.paths.sub_districts'), array_filter([
            'district_id' => $districtId,
            'sub_district_name' => $name,
            'postal_code' => $postalCode,
        ], fn ($value): bool => filled($value)));

        return $this->locations($response);
    }

    public function resolve(array $payload): array
    {
        $response = $this->client->post(config('services.everpro.paths.resolve'), $payload);

        return (array) (data_get($response, 'data') ?? $response);
    }

    /** @return array<int, array<string, mixed>> */
    private function locations(array $response): array
    {
        $data = data_get($response, 'data');

        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        foreach (['provinces', 'cities', 'districts', 'sub_districts', 'subDistricts', 'locations'] as $key) {
            $locations = data_get($data, $key);

            if (is_array($locations)) {
                return $locations;
            }
        }

        return [];
    }
}
