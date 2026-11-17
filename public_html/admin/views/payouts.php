<?php
use MajiLink\Services\PayoutService;
use MajiLink\Support\Db;

$payouts = new PayoutService();
$status = (string) ($_GET['status'] ?? '');
$rows = $payouts->listForAdmin($status);
$statuses = Db::all('SELECT name FROM payout_statuses ORDER BY id');
?>
<h1>Payouts</h1>
<p class="lead">Vendors request a payout, staff approve it, then record the M-Pesa receipt once the money is sent.</p>

<div class="tabs">
    <?php foreach (['Requested', 'Approved', 'Paid', 'Rejected', ''] as $tab): ?>
        <a class="<?= $status === $tab ? 'active' : '' ?>" href="<?= e(admin_url('payouts', ['status' => $tab])) ?>"><?= e($tab === '' ? 'All' : $tab) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Vendor</th><th class="num">Amount</th><th>Payout phone</th><th>Status</th><th>Requested</th><th>Receipt</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="empty">No payouts in this state.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="<?= e(admin_url('vendors', ['id' => $row['vendor_id']])) ?>"><?= e($row['business_name']) ?></a><br><small><?= e($row['vendor_owner']) ?></small></td>
                    <td class="num"><?= e(money($row['amount'])) ?></td>
                    <td><?= e($row['payout_phone']) ?></td>
                    <td><?= chip($row['status']) ?></td>
                    <td><?= e($row['requested_at']) ?></td>
                    <td><?= e($row['mpesa_receipt'] ?? '—') ?></td>
                    <td>
                        <div class="actions">
                            <?php if ($row['status'] === 'Requested'): ?>
                                <form method="post" action="<?= e(admin_url('payouts')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payout_approve">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="return" value="payouts">
                                    <button class="btn btn-sm" type="submit">Approve</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($row['status'] === 'Approved'): ?>
                                <form method="post" action="<?= e(admin_url('payouts')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payout_paid">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="return" value="payouts">
                                    <input type="text" name="mpesa_receipt" placeholder="M-Pesa receipt" required style="min-width:130px">
                                    <button class="btn btn-success btn-sm" type="submit">Mark paid</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($row['status'], ['Requested', 'Approved'], true)): ?>
                                <form method="post" action="<?= e(admin_url('payouts')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payout_reject">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="return" value="payouts">
                                    <input type="text" name="reason" placeholder="Reason" required>
                                    <button class="btn btn-ghost btn-sm" type="submit">Reject</button>
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
