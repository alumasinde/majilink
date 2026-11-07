<?php
declare(strict_types=1);

namespace MajiLink\Support;

final class Clock
{
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function at(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
