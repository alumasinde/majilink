<?php
use MajiLink\Services\RoleService;
use MajiLink\Services\UserAdminService;

$service = new RoleService();
$roles = $service->roles();
$permissions = $service->permissions();
$staff = (new UserAdminService())->staff();
$selectedStaff = (int) ($_GET['user_id'] ?? ($staff[0]['id'] ?? 0));
$selectedRoles = $selectedStaff > 0 ? $service->staffRoles($selectedStaff) : [];
?>
<h1>Roles and permissions</h1>
<p class="lead">
    Access is decided by permissions, never by role names. The admin role always keeps every permission so the
    platform cannot lock itself out.
</p>

<?php foreach ($roles as $role): ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap">
            <div>
                <h2 style="margin-bottom:2px"><?= e($role['name']) ?></h2>
                <p class="stat-note" style="margin:0"><?= e($role['description'] ?? '') ?> · <?= e($role['user_count']) ?> user(s)</p>
            </div>
            <?php if ($role['name'] === 'admin'): ?>
                <span class="chip chip-blue">All permissions</span>
            <?php endif; ?>
        </div>

        <?php if ($role['name'] !== 'admin'): ?>
            <form method="post" action="<?= e(admin_url('roles')) ?>" style="margin-top:12px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="role_permissions">
                <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
                <input type="hidden" name="return" value="roles">
                <div class="grid grid-3">
                    <?php foreach ($permissions as $permission): ?>
                        <label style="font-weight:500; display:flex; gap:8px; align-items:center; margin:0">
                            <input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>"
                                <?= in_array((int) $permission['id'], $role['permission_ids'], true) ? 'checked' : '' ?>
                                   style="width:auto; min-height:auto">
                            <span><?= e($permission['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn" type="submit" style="margin-top:12px">Save permissions</button>
            </form>
        <?php else: ?>
            <p class="hint">Permissions for this role are fixed.</p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="grid grid-2">
    <div class="card">
        <h2>Assign roles to a staff member</h2>
        <form method="get" action="<?= e(admin_url('roles')) ?>">
            <input type="hidden" name="p" value="roles">
            <div class="field">
                <label for="user_id">Staff member</label>
                <select id="user_id" name="user_id" onchange="this.form.submit()">
                    <?php foreach ($staff as $member): ?>
                        <option value="<?= (int) $member['id'] ?>" <?= $selectedStaff === (int) $member['id'] ? 'selected' : '' ?>>
                            <?= e($member['first_name'] . ' ' . $member['last_name'] . ' (' . $member['roles'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <form method="post" action="<?= e(admin_url('roles')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="roles_assign">
            <input type="hidden" name="user_id" value="<?= (int) $selectedStaff ?>">
            <input type="hidden" name="return" value="roles">
            <?php foreach ($roles as $role): ?>
                <label style="font-weight:500; display:flex; gap:8px; align-items:center; margin-bottom:6px">
                    <input type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                        <?= in_array((int) $role['id'], $selectedRoles, true) ? 'checked' : '' ?>
                           style="width:auto; min-height:auto">
                    <span><?= e($role['name']) ?></span>
                </label>
            <?php endforeach; ?>
            <button class="btn" type="submit">Save roles</button>
        </form>
    </div>

    <div class="card">
        <h2>Create a role</h2>
        <form method="post" action="<?= e(admin_url('roles')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="role_create">
            <input type="hidden" name="return" value="roles">
            <div class="field">
                <label for="name">Role name</label>
                <input id="name" type="text" name="name" required pattern="[a-z_]{3,40}" placeholder="e.g. dispatcher">
                <div class="hint">Lowercase letters and underscores.</div>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input id="description" type="text" name="description" maxlength="255">
            </div>
            <div class="field">
                <label>Permissions</label>
                <div style="max-height:220px; overflow:auto; border:1px solid var(--border); border-radius:9px; padding:10px">
                    <?php foreach ($permissions as $permission): ?>
                        <label style="font-weight:500; display:flex; gap:8px; align-items:center; margin-bottom:4px">
                            <input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>" style="width:auto; min-height:auto">
                            <span><?= e($permission['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn" type="submit">Create role</button>
        </form>
    </div>
</div>
