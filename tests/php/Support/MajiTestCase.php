<?php
declare(strict_types=1);

namespace MajiLink\Tests\Support;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Base for every PHPUnit test. Provides a clean test database per test, small
 * fixture builders, and assertions for the API's error shape.
 */
abstract class MajiTestCase extends TestCase
{
    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::wipe();
    }

    protected function lookup(string $table, string $name): int
    {
        $id = Db::value("SELECT id FROM {$table} WHERE name = ?", [$name]);
        $this->assertNotNull($id, "Lookup {$table}.{$name} is missing from the seed data.");

        return (int) $id;
    }

    protected function setting(string $key, mixed $value): void
    {
        TestDatabase::settings([$key => $value]);
    }

    protected function createUser(string $role = 'customer', array $overrides = []): array
    {
        $n = ++self::$sequence;
        $id = Db::insert('users', array_merge([
            'first_name'        => 'Test',
            'last_name'         => 'User' . $n,
            'phone'             => sprintf('0755%06d', $n),
            'email'             => "user{$n}@majilink.test",
            'password_hash'     => \MajiLink\Support\Hashing::make('Maji12345!'),
            'is_active'         => 1,
            'phone_verified_at' => gmdate('Y-m-d H:i:s'),
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides));

        if ($role !== '') {
            Db::insert('user_roles', [
                'user_id' => $id,
                'role_id' => $this->lookup('roles', $role),
            ]);
        }

        return Db::one('SELECT * FROM users WHERE id = ?', [$id]) ?? [];
    }

    /**
     * Approved, online, heartbeat-fresh vendor at the given pin.
     */
    protected function createVendor(array $overrides = []): array
    {
        $owner = $this->createUser('vendor');
        $id = Db::insert('vendors', array_merge([
            'user_id'               => $owner['id'],
            'business_name'         => 'Test Water Point ' . $owner['id'],
            'water_source_id'       => $this->lookup('water_sources', 'Borehole'),
            'price_per_20l'         => '60.00',
            'delivery_radius_km'    => '3.00',
            'base_lat'              => -1.2921000,
            'base_lng'              => 36.8219000,
            'opens_at'              => null,
            'closes_at'             => null,
            'payout_phone'          => $owner['phone'],
            'is_online'             => 1,
            'last_heartbeat_at'     => gmdate('Y-m-d H:i:s'),
            'application_status_id' => $this->lookup('vendor_application_statuses', 'Approved'),
        ], $overrides));

        return Db::one('SELECT * FROM vendors WHERE id = ?', [$id]) ?? [];
    }

    protected function createLocation(int $userId, float $lat = -1.2921000, float $lng = 36.8219000, string $alias = 'Home'): array
    {
        $id = Db::insert('locations', [
            'user_id'       => $userId,
            'alias'         => $alias,
            'lat'           => $lat,
            'lng'           => $lng,
            'is_default'    => 1,
        ]);

        return Db::one('SELECT * FROM locations WHERE id = ?', [$id]) ?? [];
    }

    /** Asserts the call throws an HttpException with the given status (and optional error code). */
    protected function assertHttpStatus(int $status, callable $callback, ?string $errorCode = null): HttpException
    {
        try {
            $callback();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status, 'Unexpected error status: ' . $e->getMessage());
            if ($errorCode !== null) {
                $this->assertSame($errorCode, $e->errorCode);
            }

            return $e;
        }

        $this->fail("Expected an HttpException with status {$status}, none was thrown.");
    }
}
