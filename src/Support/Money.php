<?php
declare(strict_types=1);

namespace MajiLink\Support;

/**
 * All money arithmetic runs in integer cents; the database stores DECIMAL(10,2).
 */
final class Money
{
    public static function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function display(int $cents): string
    {
        return 'KES ' . number_format($cents / 100, 2);
    }

    public static function percentOf(int $cents, float $percent): int
    {
        return (int) round($cents * $percent / 100);
    }
}
