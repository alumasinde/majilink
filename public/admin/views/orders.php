<?php
use MajiLink\Services\OrderService;
use MajiLink\Support\Db;

$orders = new OrderService();
$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0):
    $order = $orders->present($orders->row($detailId), 'admin');
    $history = $orders->history($detailId);
    $statuses = $orders->statuses();
    $vendorUser = Db::one('SELECT phone FROM users WHERE id = (SELECT user_id FROM vendors WHERE id = ?)', [$order['vendor']['id']]);
?>
    <p><a href="<?= e(admin_url('orders')) ?>">← Back to orders</a></p>
    <h1><?= e($order['order_no']) ?></h1>
    <p class="lead"><?= chip($order['status']['name']) ?> · placed <?= e($order['requested_at']) ?> UTC</p>

    <div class="grid grid-3">
        <div class="card">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= e(money($order['total'])) ?></div>
            <div class="stat-note">Commission <?= e(money($order['commission_amount'])) ?></div>
        </div>
        <div class="card">
            <div class="stat-label">Payment</div>
            <div class="stat-value" style="font-size:18px"><?= e($order['payment']['method'] ?? '—') ?></div>
            <div class="stat-note"><?= e($order['payment']['status'] ?? 'no payment') ?><?= $order['payment']['receipt'] ? ' · ' . e($order['payment']['receipt']) : '' ?></div>
        </div>
        <div class="card">
            <div class="stat-label">Distance</div>
            <div class="stat-value"><?= e($order['distance_km']) ?> km</div>
            <div class="stat-note"><?= (int) $order['quantity'] ?> jerrycans at <?= e(money($order['unit_price'])) ?></div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:16px">
        <div class="card">
            <h2>Parties</h2>
            <dl class="kv">
                <dt>Customer</dt><dd><?= e($order['customer']['first_name'] . ' ' . $order['customer']['last_name']) ?><br><small><?= e($order['customer']['phone']) ?></small></dd>
                <dt>Vendor</dt><dd><?= e($order['vendor']['business_name']) ?><br><small><?= e($vendorUser['phone'] ?? '') ?></small></dd>
                <dt>Delivery pin</dt>
                <dd>
                    <?= e($order['delivery']['alias']) ?><br>
                    <?= e($order['delivery']['lat'] . ', ' . $order['delivery']['lng']) ?><br>
                    <a target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&origin=<?= e($order['vendor']['base_lat'] . ',' . $order['vendor']['base_lng']) ?>&destination=<?= e($order['delivery']['lat'] . ',' . $order['delivery']['lng']) ?>">Route in Maps</a>
                </dd>
                <dt>Note</dt><dd><?= e($order['note'] ?? '—') ?></dd>
            </dl>
            <h2 style="margin-top:20px">Status timeline</h2>
            <ul class="timeline">
                <?php foreach ($history as $step): ?>
                    <li>
                        <strong><?= e($step['status']) ?></strong>
                        <small><?= e($step['created_at']) ?> UTC<?= $step['changed_by'] ? ' · ' . e($step['changed_by']) : '' ?></small>
                        <?php if ($step['note']): ?><span><?= e($step['note']) ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if (can('orders.override_status')): ?>
            <div class="card">
                <h2>Override status</h2>
                <p class="stat-note">Every override requires a reason and is written to the audit log.</p>
                <form method="post" action="<?= e(admin_url('orders', ['id' => $detailId])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="order_override">
                    <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                    <input type="hidden" name="return" value="orders">
                    <div class="field">
                        <label for="status">New status</label>
                        <select id="status" name="status" required>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status['name']) ?>" <?= $status['name'] === $order['status']['name'] ? 'selected' : '' ?>><?= e($status['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="reason">Reason</label>
                        <textarea id="reason" name="reason" required maxlength="255"></textarea>
                    </div>
                    <button class="btn" type="submit">Apply override</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php
    $status = (string) ($_GET['status'] ?? '');
    $search = (string) ($_GET['q'] ?? '');
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    $rows = $orders->listForAdmin($status, $search, $from, $to);
    $statuses = $orders->statuses();
    ?>
    <h1>Orders</h1>
    <p class="lead">Filter by status, date, vendor or customer.</p>

    <form class="filter-bar" method="get" action="<?= e(admin_url('orders')) ?>">
        <input type="hidden" name="p" value="orders">
        <div class="field">
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="<?= e($search) ?>" placeholder="Order no, phone, vendor">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $option): ?>
                    <option value="<?= e($option['name']) ?>" <?= $status === $option['name'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="from">From</label>
            <input id="from" type="date" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input id="to" type="date" name="to" value="<?= e($to) ?>">
        </div>
        <button class="btn" type="submit">Filter</button>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Order</th><th>Customer</th><th>Vendor</th><th>Area</th><th class="num">Qty</th><th class="num">Total</th><th>Payment</th><th>Status</th><th>Placed</th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="empty">No orders match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= e(admin_url('orders', ['id' => $row['id']])) ?>"><?= e($row['order_no']) ?></a></td>
                        <td><?= e($row['customer_name']) ?><br><small><?= e($row['customer_phone']) ?></small></td>
                        <td><?= e($row['business_name']) ?></td>
                        <td><?= e($row['delivery_alias']) ?></td>
                        <td class="num"><?= e($row['quantity']) ?></td>
                        <td class="num"><?= e(money($row['total'])) ?></td>
                        <td><?= e($row['payment_method'] ?? '—') ?><br><small><?= e($row['payment_status'] ?? '') ?></small></td>
                        <td><?= chip($row['status']) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
