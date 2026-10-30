<?php
declare(strict_types=1);

namespace MajiLink\Middleware;

use MajiLink\Http\HttpException;
use MajiLink\Http\Request;
use MajiLink\Support\Config;
use MajiLink\Support\Db;
use MajiLink\Support\Jwt;

/**
 * Request-scoped auth context. Permission checks are the only access rule:
 * code asks for a permission, never for a role name.
 */
final class Authenticator
{
    private static ?array $user = null;
    private static ?array $device = null;
    private static array $permissions = [];
    private static array $roles = [];

    public static function authenticate(Request $request): void
    {
        $token = $request->bearer();
        if ($token === null) {
            throw HttpException::unauthorized('A bearer access token is required.');
        }
        $key = (string) Config::get('key');
        try {
            [$claims] = Jwt::decode($token, $key);
        } catch (\RuntimeException $e) {
            throw HttpException::unauthorized('Token is invalid or has expired.');
        }
        if (($claims['typ'] ?? '') !== 'access') {
            throw HttpException::unauthorized('Wrong token type.');
        }
        $user = Db::one(
            'SELECT id, first_name, last_name, phone, email, is_active, phone_verified_at, email_verified_at
             FROM users WHERE id = ?',
            [$claims['sub'] ?? 0]
        );
        if ($user === null) {
            throw HttpException::unauthorized('Account no longer exists.');
        }
        if ((int) $user['is_active'] !== 1) {
            throw new HttpException(403, 'account_disabled', 'This account has been deactivated.');
        }
        $device = Db::one(
            'SELECT * FROM user_devices WHERE id = ? AND user_id = ?',
            [$claims['dev'] ?? 0, $user['id']]
        );
        if ($device === null || $device['revoked_at'] !== null) {
            throw new HttpException(401, 'device_revoked', 'This device has been signed out.');
        }

        self::$user = $user;
        self::$device = $device;
        self::$permissions = self::permissionsFor((int) $user['id']);
        self::$roles = self::rolesFor((int) $user['id']);
    }

    public static function permissionsFor(int $userId): array
    {
        $rows = Db::all(
            'SELECT DISTINCT p.name
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?',
            [$userId]
        );
        return array_column($rows, 'name');
    }

    public static function rolesFor(int $userId): array
    {
        $rows = Db::all(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$userId]
        );
        return array_column($rows, 'name');
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::$permissions, true);
    }

    public static function require(string $permission): void
    {
        if (!self::can($permission)) {
            throw HttpException::forbidden('You do not have the "' . $permission . '" permission.');
        }
    }

    public static function user(): array
    {
        return self::$user ?? throw HttpException::unauthorized();
    }

    public static function device(): array
    {
        return self::$device ?? throw HttpException::unauthorized();
    }

    public static function id(): int
    {
        return (int) (self::$user['id'] ?? 0);
    }

    public static function permissions(): array
    {
        return self::$permissions;
    }

    public static function roles(): array
    {
        return self::$roles;
    }

    /** Admin portal sessions reuse this to load a staff context without a device. */
    public static function setSessionUser(int $userId): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        self::$user = $user;
        self::$device = null;
        self::$permissions = self::permissionsFor($userId);
        self::$roles = self::rolesFor($userId);
    }
}
