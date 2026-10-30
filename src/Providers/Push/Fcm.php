<?php
declare(strict_types=1);

namespace MajiLink\Providers\Push;

use MajiLink\Support\Config;

/**
 * Firebase Cloud Messaging HTTP v1. Pushes are best effort: if credentials are
 * absent or the call fails, the notification row is still stored and readable.
 */
final class Fcm
{
    /** @var array{token:string,expires:int}|null */
    private static ?array $cachedToken = null;

    public static function isConfigured(): bool
    {
        return Config::get('fcm.project_id') !== ''
            && is_file((string) Config::get('fcm.service_account'));
    }

    public static function send(string $deviceToken, string $title, string $body, array $data = [], string $channel = 'majilink_default'): bool
    {
        if ($deviceToken === '' || !self::isConfigured()) {
            return false;
        }
        $accessToken = self::accessToken();
        if ($accessToken === null) {
            return false;
        }
        $projectId = (string) Config::get('fcm.project_id');
        $payload = [
            'message' => [
                'token'        => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => array_map('strval', $data),
                'android'      => [
                    'priority'     => 'high',
                    'notification' => ['channel_id' => $channel, 'sound' => 'default'],
                ],
            ],
        ];
        $result = self::request(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            $payload,
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']
        );
        return $result !== null && ($result['name'] ?? null) !== null;
    }

    private static function accessToken(): ?string
    {
        if (self::$cachedToken !== null && self::$cachedToken['expires'] > time() + 30) {
            return self::$cachedToken['token'];
        }
        $path = (string) Config::get('fcm.service_account');
        $json = json_decode((string) @file_get_contents($path), true);
        if (!is_array($json)) {
            return null;
        }
        $now = time();
        $claims = [
            'iss'   => $json['client_email'] ?? '',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $segments = [
            self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::b64(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);
        $key = openssl_pkey_get_private($json['private_key'] ?? '');
        if ($key === false) {
            return null;
        }
        openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        $assertion = $signingInput . '.' . self::b64($signature);

        $response = self::request(
            'https://oauth2.googleapis.com/token',
            [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
            ['Content-Type: application/x-www-form-urlencoded'],
            true
        );
        if (!isset($response['access_token'])) {
            return null;
        }
        self::$cachedToken = ['token' => $response['access_token'], 'expires' => $now + (int) ($response['expires_in'] ?? 3600)];
        return $response['access_token'];
    }

    private static function request(string $url, array $payload, array $headers, bool $form = false): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $form ? http_build_query($payload) : json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
