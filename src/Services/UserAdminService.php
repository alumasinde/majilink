<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Hashing;
use MajiLink\Support\Validator;

final class UserAdminService
{
    public function __construct(private readonly AuditService $audit = new AuditService())
    {
    }

    public function customers(?string $search = null): array
    {
        $sql = "SELECT u.id, u.first_name, u.last_name, u.phone, u.email, u.is_active, u.created_at,
                       (SELECT COUNT(*) FROM orders o WHERE o.customer_id = u.id) AS orders_count,
                       (SELECT COUNT(*) FROM user_devices d WHERE d.user_id = u.id AND d.revoked_at IS NULL) AS devices_count
                FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE r.name = 'customer'";
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        $sql .= ' ORDER BY u.created_at DESC LIMIT 200';
        return Db::all($sql, $params);
    }

    public function staff(): array
    {
        return Db::all(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.is_active, u.last_login_at,
                    GROUP_CONCAT(r.name ORDER BY r.name) AS roles
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.name IN ('admin', 'support')
             GROUP BY u.id
             ORDER BY u.id"
        );
    }

    public function view(int $userId): array
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }
        $user['roles'] = Db::all(
            'SELECT r.id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$userId]
        );
        $user['devices'] = Db::all('SELECT * FROM user_devices WHERE user_id = ? ORDER BY id DESC', [$userId]);
        $user['orders'] = Db::all(
            'SELECT o.id, o.order_no, o.total, o.created_at, s.name AS status, v.business_name
             FROM orders o JOIN order_statuses s ON s.id = o.status_id JOIN vendors v ON v.id = o.vendor_id
             WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT 20',
            [$userId]
        );
        $user['vendor'] = Db::one(
            'SELECT v.*, s.name AS status FROM vendors v JOIN vendor_application_statuses s ON s.id = v.application_status_id WHERE v.user_id = ?',
            [$userId]
        );
        return $user;
    }

    public function setActive(int $userId, bool $active, int $adminId, ?string $ip = null): void
    {
        if ($userId === $adminId && !$active) {
            throw HttpException::conflict('You cannot deactivate your own account.');
        }
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }
        Db::update('users', ['is_active' => $active ? 1 : 0], 'id = ?', [$userId]);
        if (!$active) {
            Db::run('UPDATE user_devices SET refresh_token_hash = NULL WHERE user_id = ?', [$userId]);
        }
        $this->audit->record($adminId, $active ? 'users.activate' : 'users.deactivate', 'users', $userId, [], $ip);
    }

    public function revokeDevices(int $userId, int $adminId, ?string $ip = null): int
    {
        $count = Db::run(
            'UPDATE user_devices SET revoked_at = UTC_TIMESTAMP(), refresh_token_hash = NULL, pin_hash = NULL
             WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        )->rowCount();
        $this->audit->record($adminId, 'users.devices.revoke', 'users', $userId, ['count' => $count], $ip);
        return $count;
    }

    public function createStaff(array $data, int $adminId, ?string $ip = null): int
    {
        $input = Validator::validate($data, [
            'first_name' => 'required|string|min:2|max:80',
            'last_name'  => 'required|string|min:2|max:80',
            'email'      => 'required|email|max:190|unique:users,email',
            'phone'      => 'required|phone|unique:users,phone',
            'password'   => 'required|password',
            'role'       => 'required|in:admin,support',
        ]);
        $id = Db::transaction(function () use ($input): int {
            $userId = Db::insert('users', [
                'first_name'    => $input['first_name'],
                'last_name'     => $input['last_name'],
                'email'         => mb_strtolower((string) $input['email']),
                'phone'         => (new OtpService())->normalize((string) $input['phone']),
                'password_hash' => Hashing::make((string) $input['password']),
                'is_active'     => 1,
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $roleId = (int) Db::value('SELECT id FROM roles WHERE name = ?', [$input['role']]);
            Db::insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
            return $userId;
        });
        $this->audit->record($adminId, 'staff.create', 'users', $id, ['role' => $input['role']], $ip);
        return $id;
    }

    public function updatePassword(int $userId, string $password, int $adminId, ?string $ip = null): void
    {
        Validator::validate(['password' => $password], ['password' => 'required|password']);
        Db::update('users', ['password_hash' => Hashing::make($password)], 'id = ?', [$userId]);
        $this->audit->record($adminId, 'users.password.update', 'users', $userId, [], $ip);
    }
}
