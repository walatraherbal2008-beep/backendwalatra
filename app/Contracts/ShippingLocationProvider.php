<?php

namespace App\Contracts;

interface ShippingLocationProvider
{
    /** @return array<int, array<string, mixed>> */
    public function provinces(?string $search = null): array;

    /** @return array<int, array<string, mixed>> */
    public function cities(?string $provinceId = null, ?string $provinceName = null, ?string $cityName = null): array;

    /** @return array<int, array<string, mixed>> */
    public function districts(?string $cityId = null, ?string $districtName = null): array;

    /** @return array<int, array<string, mixed>> */
    public function subDistricts(?string $districtId = null, ?string $name = null, ?string $postalCode = null): array;

    /** @return array<string, mixed> */
    public function resolve(array $payload): array;
}
