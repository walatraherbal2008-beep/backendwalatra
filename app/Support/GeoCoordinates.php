<?php

namespace App\Support;

final class GeoCoordinates
{
    public static function valid(mixed $latitude, mixed $longitude): bool
    {
        return is_numeric($latitude) && is_numeric($longitude)
            && is_finite((float) $latitude) && is_finite((float) $longitude)
            && abs((float) $latitude) <= 90 && abs((float) $longitude) <= 180;
    }

    /** @return array{latitude: float, longitude: float}|null */
    public static function parse(?string $coordinates): ?array
    {
        if (! preg_match('/^\s*([-+]?\d+(?:\.\d+)?)\s*,\s*([-+]?\d+(?:\.\d+)?)\s*$/D', $coordinates ?? '', $matches)
            || ! self::valid($matches[1], $matches[2])) {
            return null;
        }

        return ['latitude' => (float) $matches[1], 'longitude' => (float) $matches[2]];
    }

    public static function distanceKm(float $latitude, float $longitude, float $otherLatitude, float $otherLongitude): float
    {
        $latitudeDelta = deg2rad($otherLatitude - $latitude);
        $longitudeDelta = deg2rad($otherLongitude - $longitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($otherLatitude)) * sin($longitudeDelta / 2) ** 2;

        return 6371.0088 * 2 * asin(sqrt(max(0, min(1, $a))));
    }
}
