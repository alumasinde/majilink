<?php
use MajiLink\Services\UserAdminService;
use MajiLink\Support\Db;

$users = new UserAdminService();
$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0):
    $customer = $users->view($detailId);
?>
    <p><a href="<?= e(admin_url('customers')) ?>">← Back to customers</a></p>
    <h1><?= e($customer['first_name'] . ' ' . $customer['last_name']) ?></h1>
    <p class="lead">
        <?= e($customer['phone']) ?> · <?= e($customer['email'] ?? 'no email') ?> ·
        <?= (int) $customer['is_active'] === 1 ? 'Active' : 'Deactivated' ?>
    </p>

    <div class="grid grid-2">
        <div class="card">
            <h2>Devices</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Device</th><th>PIN</th><th>Last used</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if ($customer['devices'] === []): ?>
                        <tr><td colspan="4" class="empty">No devices yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($customer['devices'] as $device): ?>
                        <tr>
                            <td><?= e($device['device_name'] ?? $device['device_uid']) ?></td>
                            <td><?= $device['pin_hash'] !== null ? 'Set' : 'Not set' ?></td>
                            <td><?= e($device['last_used_at'] ?? '—') ?></td>
                            <td><?= $device['revoked_at'] !== null ? chip('Revoked') : chip('Active') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Account</h2>
            <div class="actions">
                <?php if (can('users.manage')): ?>
                    <form method="post" action="<?= e(admin_url('customers', ['id' => $detailId])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="user_toggle_active">
                        <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                        <input type="hidden" name="active" value="<?= (int) $customer['is_active'] === 1 ? '0' : '1' ?>">
                        <input type="hidden" name="return" value="customers">
                        <button class="btn <?= (int) $customer['is_active'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm" type="submit">
                            <?= (int) $customer['is_active'] === 1 ? 'Deactivate account' : 'Reactivate account' ?>
                        </button>
                    </form>
                    <form method="post" action="<?= e(admin_url('customers', ['id' => $detailId])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="user_revoke_devices">
                        <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                        <input type="hidden" name="return" value="customers">
                        <button class="btn btn-ghost btn-sm" type="submit">Sign out all devices</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($customer['vendor']): ?>
                <h2 style="margin-top:20px">Vendor account</h2>
                <p><?= e($customer['vendor']['business_name']) ?> · <?= chip($customer['vendor']['status']) ?></p>
                <a href="<?= e(admin_url('vendors', ['id' => $customer['vendor']['id']])) ?>">Open vendor record</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Orders</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Order</th><th>Vendor</th><th>Status</th><th class="num">Total</th><th>Placed</th></tr></thead>
                <tbody>
                <?php if ($customer['orders'] === []): ?>
                    <tr><td colspan="5" class="empty">No orders yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($customer['orders'] as $order): ?>
                    <tr>
                        <td><a href="<?= e(admin_url('orders', ['id' => $order['id']])) ?>"><?= e($order['order_no']) ?></a></td>
                        <td><?= e($order['business_name']) ?></td>
                        <td><?= chip($order['status']) ?></td>
                        <td class="num"><?= e(money($order['total'])) ?></td>
                        <td><?= e($order['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <?php
    $search = (string) ($_GET['q'] ?? '');
    $rows = $users->customers($search);
    ?>
    <h1>Customers</h1>
    <p class="lead">Households ordering water.</p>

    <form class="filter-bar" method="get" action="<?= e(admin_url('customers')) ?>">
        <input type="hidden" name="p" value="customers">
        <div class="field">
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="<?= e($search) ?>" placeholder="Name, phone or email">
        </div>
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Customer</th><th>Phone</th><th>Email</th><th class="num">Orders</th><th class="num">Devices</th><th>Status</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="empty">No customers found.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                        <td><?= e($row['phone']) ?></td>
                        <td><?= e($row['email'] ?? '—') ?></td>
                        <td class="num"><?= e($row['orders_count']) ?></td>
                        <td class="num"><?= e($row['devices_count']) ?></td>
                        <td><?= (int) $row['is_active'] === 1 ? chip('Active') : chip('Cancelled') ?></td>
                        <td><?= e(substr($row['created_at'], 0, 10)) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('customers', ['id' => $row['id']])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
