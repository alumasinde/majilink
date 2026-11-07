<?php
declare(strict_types=1);

namespace MajiLink\Http;

final class Response
{
    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = (array) \MajiLink\Support\Config::get('allowed_origins', []);
        if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-App-Version-Code, X-App-Version-Name, X-Device-Uid');
        header('Access-Control-Max-Age: 600');
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(mixed $data = null, array $meta = []): never
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::json($payload);
    }

    public static function created(mixed $data = null): never
    {
        self::json(['data' => $data], 201);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function error(int $status, string $code, string $message, array $fields = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        self::json(['error' => $error], $status);
    }
}
