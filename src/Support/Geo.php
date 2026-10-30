<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Geo
{
    public const EARTH_RADIUS_KM = 6371.0088;

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round(self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a)), 3);
    }

    /**
     * Bounding box used to hit the (base_lat, base_lng) index before the Haversine pass.
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.32;
        $lngDelta = $radiusKm / max(0.000001, (111.32 * cos(deg2rad($lat))));
        return [
            'min_lat' => $lat - $latDelta,
            'max_lat' => $lat + $latDelta,
            'min_lng' => $lng - $lngDelta,
            'max_lng' => $lng + $lngDelta,
        ];
    }
}
