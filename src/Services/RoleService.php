<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;

final class RoleService
{
    public function __construct(private readonly AuditService $audit = new AuditService())
    {
    }

    public function permissions(): array
    {
        return Db::all('SELECT id, name, description FROM permissions ORDER BY name');
    }

    public function roles(): array
    {
        $roles = Db::all('SELECT id, name, description FROM roles ORDER BY FIELD(name, "admin", "support", "vendor", "customer"), name');
        foreach ($roles as &$role) {
            $role['permission_ids'] = array_map(
                'intval',
                array_column(Db::all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$role['id']]), 'permission_id')
            );
            $role['user_count'] = (int) Db::value('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$role['id']]);
        }
        return $roles;
    }

    public function createRole(string $name, ?string $description, array $permissionIds, int $adminId, ?string $ip = null): int
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z_]{3,40}$/', $name)) {
            throw HttpException::validation(['name' => 'Use lowercase letters and underscores only.']);
        }
        if (Db::value('SELECT 1 FROM roles WHERE name = ?', [$name]) !== null) {
            throw HttpException::conflict('A role with that name already exists.');
        }
        $id = Db::transaction(function () use ($name, $description, $permissionIds): int {
            $roleId = Db::insert('roles', ['name' => $name, 'description' => $description]);
            $this->syncPermissions($roleId, $permissionIds);
            return $roleId;
        });
        $this->audit->record($adminId, 'roles.create', 'roles', $id, ['name' => $name, 'permissions' => $permissionIds], $ip);
        return $id;
    }

    public function updatePermissions(int $roleId, array $permissionIds, int $adminId, ?string $ip = null): void
    {
        $role = Db::one('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }
        if ($role['name'] === 'admin') {
            throw HttpException::conflict('The admin role always keeps every permission.');
        }
        Db::transaction(fn () => $this->syncPermissions($roleId, $permissionIds));
        $this->audit->record($adminId, 'roles.permissions', 'roles', $roleId, ['permissions' => $permissionIds], $ip);
    }

    public function assignRoles(int $userId, array $roleIds, int $adminId, ?string $ip = null): void
    {
        $roles = Db::all('SELECT id, name FROM roles');
        $valid = array_map(static fn ($r) => (int) $r['id'], $roles);
        foreach ($roleIds as $roleId) {
            if (!in_array((int) $roleId, $valid, true)) {
                throw HttpException::validation(['roles' => 'One of the selected roles does not exist.']);
            }
        }
        $existing = Db::all('SELECT role_id FROM user_roles WHERE user_id = ?', [$userId]);
        $before = array_map('intval', array_column($existing, 'role_id'));

        Db::transaction(function () use ($userId, $roleIds): void {
            Db::run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
            foreach (array_unique($roleIds) as $roleId) {
                Db::insert('user_roles', ['user_id' => $userId, 'role_id' => (int) $roleId]);
            }
        });
        $this->audit->record($adminId, 'users.roles.update', 'users', $userId, ['before' => $before, 'after' => array_values(array_unique(array_map('intval', $roleIds)))], $ip);
    }

    public function staffRoles(int $userId): array
    {
        return array_map(
            'intval',
            array_column(Db::all('SELECT role_id FROM user_roles WHERE user_id = ?', [$userId]), 'role_id')
        );
    }

    private function syncPermissions(int $roleId, array $permissionIds): void
    {
        Db::run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        $valid = array_column(Db::all('SELECT id FROM permissions'), 'id');
        foreach (array_unique($permissionIds) as $permissionId) {
            if (in_array((int) $permissionId, array_map('intval', $valid), true)) {
                Db::insert('role_permissions', ['role_id' => $roleId, 'permission_id' => (int) $permissionId]);
            }
        }
    }
}
