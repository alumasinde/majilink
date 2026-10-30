<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Jwt
{
    public static function encode(array $claims, string $key): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $key, true));
        return implode('.', $segments);
    }

    /**
     * @return array{0: array, 1: string} decoded claims and the raw signature segment
     */
    public static function decode(string $token, string $key): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token');
        }
        [$header, $payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $header . '.' . $payload, $key, true));
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Bad signature');
        }
        $claims = json_decode(self::unb64($payload), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Malformed claims');
        }
        if (!isset($claims['exp']) || (int) $claims['exp'] < time()) {
            throw new \RuntimeException('Token expired');
        }
        return [$claims, $signature];
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
