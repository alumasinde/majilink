<?php
declare(strict_types=1);

namespace MajiLink\Support;

/**
 * Small file-backed limiter (flock-protected) for OTP, login and PIN attempts.
 */
final class RateLimiter
{
    public static function attempt(string $bucket, string $identity, int $max, int $windowSeconds): bool
    {
        $dir = (string) Config::get('cache_dir') . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . hash('sha256', $bucket . '|' . $identity) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle) ?: '';
        $data = json_decode($raw, true) ?: ['start' => time(), 'hits' => 0];
        $now = time();
        if ($now - (int) $data['start'] >= $windowSeconds) {
            $data = ['start' => $now, 'hits' => 0];
        }
        $allowed = (int) $data['hits'] < $max;
        if ($allowed) {
            $data['hits']++;
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $allowed;
    }

    public static function clear(string $bucket, string $identity): void
    {
        $file = (string) Config::get('cache_dir') . '/ratelimit/' . hash('sha256', $bucket . '|' . $identity) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
