<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Config
{
    private static array $items = [];

    public static function set(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$items[$key] ?? $default;
    }

    public static function setValue(string $key, mixed $value): void
    {
        self::$items[$key] = $value;
    }
}
