<?php
use MajiLink\Middleware\Authenticator;
use MajiLink\Services\UserAdminService;
$users = new UserAdminService();
$rows = $users->staff();
?>
<h1>Staff users</h1>
<p class="lead">Admin and support accounts. Staff sign in with email and password, never with an app PIN.</p>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Roles</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                    <td><?= e($row['email'] ?? '—') ?></td>
                    <td><?= e($row['phone']) ?></td>
                    <td><?= e($row['roles']) ?></td>
                    <td><?= (int) $row['is_active'] === 1 ? chip('Active') : chip('Suspended') ?></td>
                    <td><?= e($row['last_login_at'] ?? '—') ?></td>
                    <td>
                        <?php if ((int) $row['id'] !== Authenticator::id()): ?>
                            <form method="post" action="<?= e(admin_url('staff')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="user_toggle_active">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="active" value="<?= (int) $row['is_active'] === 1 ? '0' : '1' ?>">
                                <input type="hidden" name="return" value="staff">
                                <button class="btn btn-ghost btn-sm" type="submit">
                                    <?= (int) $row['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Create a staff account</h2>
    <form method="post" action="<?= e(admin_url('staff')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="staff_create">
        <input type="hidden" name="return" value="staff">
        <div class="form-grid">
            <div class="field">
                <label for="first_name">First name</label>
                <input id="first_name" type="text" name="first_name" required maxlength="80">
            </div>
            <div class="field">
                <label for="last_name">Last name</label>
                <input id="last_name" type="text" name="last_name" required maxlength="80">
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required maxlength="190">
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input id="phone" type="tel" name="phone" required placeholder="0712345678">
            </div>
            <div class="field">
                <label for="password">Temporary password</label>
                <input id="password" type="password" name="password" required minlength="8">
                <div class="hint">At least 8 characters. Ask them to change it after the first sign-in.</div>
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="support">support</option>
                    <option value="admin">admin</option>
                </select>
            </div>
        </div>
        <button class="btn" type="submit">Create account</button>
    </form>
</div>
