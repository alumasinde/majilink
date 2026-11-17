<?php
use MajiLink\Services\ReportService;

$reports = new ReportService();
$from = (string) ($_GET['from'] ?? gmdate('Y-m-d', strtotime('-14 days')));
$to = (string) ($_GET['to'] ?? gmdate('Y-m-d'));
$byDay = $reports->ordersByDay($from, $to);
$byArea = $reports->ordersByArea($from, $to);
$performance = $reports->vendorPerformance($from, $to);
$revenue = $reports->revenueSummary($from, $to);
?>
<h1>Reports</h1>
<p class="lead">Orders, revenue and vendor reliability between the selected dates.</p>

<form class="filter-bar" method="get" action="<?= e(admin_url('reports')) ?>">
    <input type="hidden" name="p" value="reports">
    <div class="field">
        <label for="from">From</label>
        <input id="from" type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input id="to" type="date" name="to" value="<?= e($to) ?>">
    </div>
    <button class="btn" type="submit">Apply</button>
    <a class="btn btn-ghost" href="<?= e(admin_url('export', ['type' => 'orders', 'from' => $from, 'to' => $to])) ?>">Export days CSV</a>
    <a class="btn btn-ghost" href="<?= e(admin_url('export', ['type' => 'vendors', 'from' => $from, 'to' => $to])) ?>">Export vendors CSV</a>
</form>

<div class="grid grid-3">
    <div class="card">
        <div class="stat-label">Delivered orders</div>
        <div class="stat-value"><?= e($revenue['summary']['orders'] ?? 0) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">Delivered revenue</div>
        <div class="stat-value"><?= e(money($revenue['summary']['gross'] ?? 0)) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">Commission</div>
        <div class="stat-value"><?= e(money($revenue['summary']['commission'] ?? 0)) ?></div>
    </div>
</div>

<div class="card">
    <h2>Orders by day</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Day</th><th class="num">Orders</th><th class="num">Delivered</th><th class="num">Lost</th><th class="num">Gross</th><th class="num">Commission</th></tr></thead>
            <tbody>
            <?php if ($byDay === []): ?>
                <tr><td colspan="6" class="empty">Nothing in this range.</td></tr>
            <?php endif; ?>
            <?php foreach ($byDay as $row): ?>
                <tr>
                    <td><?= e($row['day']) ?></td>
                    <td class="num"><?= e($row['orders']) ?></td>
                    <td class="num"><?= e($row['delivered']) ?></td>
                    <td class="num"><?= e($row['lost']) ?></td>
                    <td class="num"><?= e(money($row['gross'])) ?></td>
                    <td class="num"><?= e(money($row['commission'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2" style="margin-top:16px">
    <div class="card">
        <h2>Orders by area</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Pin alias</th><th class="num">Orders</th><th class="num">Gross</th></tr></thead>
                <tbody>
                <?php if ($byArea === []): ?>
                    <tr><td colspan="3" class="empty">No data.</td></tr>
                <?php endif; ?>
                <?php foreach ($byArea as $row): ?>
                    <tr><td><?= e($row['area']) ?></td><td class="num"><?= e($row['orders']) ?></td><td class="num"><?= e(money($row['gross'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Payment mix</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Amount</th></tr></thead>
                <tbody>
                <?php if ($revenue['by_method'] === []): ?>
                    <tr><td colspan="3" class="empty">No paid payments.</td></tr>
                <?php endif; ?>
                <?php foreach ($revenue['by_method'] as $row): ?>
                    <tr><td><?= e($row['method']) ?></td><td class="num"><?= e($row['payments']) ?></td><td class="num"><?= e(money($row['amount'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h2>Vendor performance</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Vendor</th><th>Source</th><th class="num">Orders</th><th class="num">Delivered</th><th class="num">Declined</th><th class="num">Dropped</th><th class="num">Reliability</th><th class="num">Gross</th><th class="num">Rating</th></tr></thead>
            <tbody>
            <?php foreach ($performance as $row): ?>
                <tr>
                    <td><a href="<?= e(admin_url('vendors', ['id' => $row['id']])) ?>"><?= e($row['business_name']) ?></a></td>
                    <td><?= e($row['water_source']) ?></td>
                    <td class="num"><?= e($row['orders']) ?></td>
                    <td class="num"><?= e($row['delivered']) ?></td>
                    <td class="num"><?= e($row['declined']) ?></td>
                    <td class="num"><?= e($row['dropped']) ?></td>
                    <td class="num"><?= $row['reliability_percent'] === null ? '—' : e($row['reliability_percent']) . '%' ?></td>
                    <td class="num"><?= e(money($row['gross'])) ?></td>
                    <td class="num"><?= e(number_format((float) $row['avg_rating'], 1)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
