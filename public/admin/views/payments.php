<?php
use MajiLink\Services\PaymentService;
use MajiLink\Support\Db;

$payments = new PaymentService();
$status = (string) ($_GET['status'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$search = (string) ($_GET['q'] ?? '');
$rows = $payments->listForAdmin($status, $method, $search);
$statuses = Db::all('SELECT name FROM payment_statuses ORDER BY id');
$methods = Db::all('SELECT name FROM payment_methods ORDER BY id');
?>
<h1>Payments</h1>
<p class="lead">M-Pesa receipts, failed callbacks and refunds. Cash orders appear as unpaid until delivery.</p>

<form class="filter-bar" method="get" action="<?= e(admin_url('payments')) ?>">
    <input type="hidden" name="p" value="payments">
    <div class="field">
        <label for="q">Search</label>
        <input id="q" type="text" name="q" value="<?= e($search) ?>" placeholder="Order, receipt or phone">
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
        <label for="method">Method</label>
        <select id="method" name="method">
            <option value="">All</option>
            <?php foreach ($methods as $option): ?>
                <option value="<?= e($option['name']) ?>" <?= $method === $option['name'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn" type="submit">Filter</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Order</th><th>Customer</th><th>Vendor</th><th>Method</th><th class="num">Amount</th><th>Status</th><th>Receipt / Checkout</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="empty">No payments match.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="<?= e(admin_url('orders', ['id' => $row['order_id']])) ?>"><?= e($row['order_no']) ?></a></td>
                    <td><?= e($row['customer_name']) ?><br><small><?= e($row['phone'] ?? '') ?></small></td>
                    <td><?= e($row['business_name']) ?></td>
                    <td><?= e($row['method']) ?></td>
                    <td class="num"><?= e(money($row['amount'])) ?></td>
                    <td><?= chip($row['status']) ?></td>
                    <td>
                        <?= e($row['mpesa_receipt'] ?? '—') ?><br>
                        <small><?= e($row['mpesa_checkout_request_id'] ?? '') ?></small>
                    </td>
                    <td>
                        <div class="actions">
                            <?php if (in_array($row['status'], ['Failed', 'Pending', 'Unpaid'], true)): ?>
                                <form method="post" action="<?= e(admin_url('payments')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payment_mark_paid">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="return" value="payments">
                                    <input type="text" name="mpesa_receipt" placeholder="Receipt" required style="min-width:120px">
                                    <button class="btn btn-sm" type="submit">Mark paid</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($row['status'] === 'Paid'): ?>
                                <form method="post" action="<?= e(admin_url('payments')) ?>" onsubmit="return confirm('Refund this payment and debit the vendor wallet?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payment_refund">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="return" value="payments">
                                    <input type="text" name="reason" placeholder="Refund reason">
                                    <button class="btn btn-danger btn-sm" type="submit">Refund</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
