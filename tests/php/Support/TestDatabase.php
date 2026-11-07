<?php
declare(strict_types=1);

namespace MajiLink\Tests\Support;

use MajiLink\Support\Db;

/**
 * State control for the isolated test database. Lookup tables, roles,
 * permissions and settings rows survive a wipe; everything transactional does
 * not, so every test starts from the same baseline.
 */
final class TestDatabase
{
    /** Transactional tables, ordered child-first for readability only (FK checks are off). */
    public const TABLES = [
        'order_status_history',
        'payments',
        'ratings',
        'wallet_transactions',
        'vendor_wallets',
        'payouts',
        'recurring_orders',
        'orders',
        'vendor_documents',
        'vendors',
        'locations',
        'notifications',
        'audit_logs',
        'otp_codes',
        'user_devices',
        'user_roles',
        'users',
    ];

    /** Deterministic economics so price assertions never depend on seed values. */
    public const SETTINGS = [
        'commission_percent'       => '10',
        'delivery_base_fee'        => '50',
        'delivery_fee_per_km'      => '20',
        'default_radius_km'        => '3',
        'max_radius_km'            => '5',
        'min_quantity'             => '1',
        'max_quantity'             => '20',
        'order_expiry_minutes'     => '5',
        'heartbeat_timeout_minutes' => '15',
    ];

    public static function wipe(): void
    {
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            // DELETE rather than TRUNCATE: the tables are tiny and recreating
            // them per test costs seconds on Windows/InnoDB.
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        self::settings(self::SETTINGS);
    }

    /** @param array<string,mixed> $values */
    public static function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            Db::update('app_settings', ['setting_value' => (string) $value], 'setting_key = ?', [$key]);
        }
    }
}
