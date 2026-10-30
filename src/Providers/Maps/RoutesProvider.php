<?php
declare(strict_types=1);

namespace MajiLink\Providers\Maps;

use MajiLink\Support\Config;
use MajiLink\Support\Geo;

/**
 * Road distance and ETA for the top nearby vendors. Results are cached for ten
 * minutes; when the routing service is unreachable we fall back to a straight
 * line estimate so the customer list never fails.
 */
final class RoutesProvider
{
    private const TTL = 600;
    private const AVERAGE_SPEED_KMH = 22.0;
    private const ROAD_FACTOR = 1.35;

    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $key = sha1(sprintf('%.4f,%.4f,%.4f,%.4f', $fromLat, $fromLng, $toLat, $toLng));
        $cached = $this->read($key);
        if ($cached !== null) {
            return $cached;
        }

        $base = (string) Config::get('routing_url');
        $result = null;
        if ($base !== '') {
            $url = sprintf('%s/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false', rtrim($base, '/'), $fromLng, $fromLat, $toLng, $toLat);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($raw) && $status === 200) {
                $decoded = json_decode($raw, true);
                $route = $decoded['routes'][0] ?? null;
                if ($route && isset($route['distance'], $route['duration'])) {
                    $result = [
                        'distance_km' => round(((float) $route['distance']) / 1000, 3),
                        'duration_min'=> (int) ceil(((float) $route['duration']) / 60),
                        'source'      => 'road',
                    ];
                }
            }
        }

        if ($result === null) {
            $straight = Geo::distanceKm($fromLat, $fromLng, $toLat, $toLng);
            $distance = round($straight * self::ROAD_FACTOR, 3);
            $result = [
                'distance_km' => $distance,
                'duration_min'=> (int) max(1, ceil($distance / self::AVERAGE_SPEED_KMH * 60)),
                'source'      => 'straight_line',
            ];
        }

        $this->write($key, $result);
        return $result;
    }

    private function path(string $key): string
    {
        $dir = (string) Config::get('cache_dir') . '/routes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . $key . '.json';
    }

    private function read(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file) || filemtime($file) < time() - self::TTL) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function write(string $key, array $value): void
    {
        @file_put_contents($this->path($key), json_encode($value), LOCK_EX);
    }
}
