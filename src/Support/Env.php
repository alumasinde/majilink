<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Env
{
    private static array $values = [];

    /**
     * Values pinned by code before the environment loads. The test suite points
     * DB_NAME here so it can never migrate or wipe the configured database.
     */
    private static array $overrides = [];

    public static function override(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$overrides[$key]);
            return;
        }
        self::$overrides[$key] = $value;
    }

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            if (array_key_exists($key, self::$overrides)) {
                continue;
            }
            $value = trim($parts[1]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[-1]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$overrides[$key] ?? self::$values[$key] ?? ($_ENV[$key] ?? $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
