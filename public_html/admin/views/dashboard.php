<?php
use MajiLink\Support\Config;

$data = (new \MajiLink\Services\ReportService())->dashboard();
$cards = $data['cards'];
$max = max(1, ...array_map(static fn ($d) => (int) $d['orders'], $data['chart']));
?>
<h1>Dashboard</h1>
<p class="lead">Today at a glance, in UTC. Money is in KES.</p>

<div class="grid grid-4">
    <div class="card">
        <div class="stat-label">Orders today</div>
        <div class="stat-value"><?= e($cards['orders_today']) ?></div>
        <div class="stat-note">All statuses, since midnight UTC</div>
    </div>
    <div class="card">
        <div class="stat-label">Vendors online</div>
        <div class="stat-value"><?= e($cards['vendors_online']) ?></div>
        <div class="stat-note">Approved and heartbeating</div>
    </div>
    <div class="card">
        <div class="stat-label">New customers (7 days)</div>
        <div class="stat-value"><?= e($cards['new_customers_7d']) ?></div>
        <div class="stat-note">Registered customer accounts</div>
    </div>
    <div class="card">
        <div class="stat-label">Delivered revenue today</div>
        <div class="stat-value"><?= e(money($cards['revenue_today'])) ?></div>
        <div class="stat-note">Commission <?= e(money($cards['commission_today'])) ?></div>
    </div>
</div>

<div class="grid grid-4" style="margin-top:16px">
    <div class="card">
        <div class="stat-label">Pending applications</div>
        <div class="stat-value"><?= e($cards['pending_applications']) ?></div>
        <div class="stat-note"><a href="<?= e(admin_url('applications')) ?>">Review queue</a></div>
    </div>
    <div class="card">
        <div class="stat-label">Failed payments</div>
        <div class="stat-value"><?= e($cards['failed_payments']) ?></div>
        <div class="stat-note"><a href="<?= e(admin_url('payments', ['status' => 'Failed'])) ?>">Inspect</a></div>
    </div>
    <div class="card">
        <div class="stat-label">Payouts waiting</div>
        <div class="stat-value"><?= e($cards['pending_payouts']) ?></div>
        <div class="stat-note"><a href="<?= e(admin_url('payouts', ['status' => 'Requested'])) ?>">Process</a></div>
    </div>
    <div class="card">
        <div class="stat-label">Platform</div>
        <div class="stat-value" style="font-size:20px"><?= e(Config::get('env') === 'local' ? 'Local' : 'Live') ?></div>
        <div class="stat-note">Cells below are per day</div>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <h2>Orders, last 14 days</h2>
    <div class="chart">
        <?php foreach ($data['chart'] as $day): ?>
            <div class="chart-bar" style="height: <?= (int) max(3, round($day['orders'] / $max * 100)) ?>%"
                 title="<?= e($day['day']) ?>: <?= e($day['orders']) ?> orders"></div>
        <?php endforeach; ?>
    </div>
    <div class="chart-labels">
        <?php foreach ($data['chart'] as $day): ?>
            <span><?= e(gmdate('d/m', strtotime($day['day']))) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <h2>Recent orders</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr><th>Order</th><th>Customer</th><th>Vendor</th><th>Status</th><th class="num">Total</th><th>Placed</th></tr>
            </thead>
            <tbody>
            <?php if ($data['recent'] === []): ?>
                <tr><td colspan="6" class="empty">No orders yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($data['recent'] as $order): ?>
                <tr>
                    <td><a href="<?= e(admin_url('orders', ['id' => $order['id']])) ?>"><?= e($order['order_no']) ?></a></td>
                    <td><?= e($order['customer']) ?></td>
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
