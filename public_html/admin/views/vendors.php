<?php
use MajiLink\Services\OrderService;
use MajiLink\Services\VendorService;
use MajiLink\Services\WalletService;
use MajiLink\Support\Db;

$vendors = new VendorService();
$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0):
    $vendor = Db::one(
        'SELECT v.*, s.name AS status, ws.name AS water_source, u.first_name, u.last_name, u.phone, u.email
         FROM vendors v
         JOIN vendor_application_statuses s ON s.id = v.application_status_id
         JOIN water_sources ws ON ws.id = v.water_source_id
         JOIN users u ON u.id = v.user_id
         WHERE v.id = ?',
        [$detailId]
    );
    if ($vendor === null) {
        echo '<div class="card"><p>Vendor not found.</p></div>';
        return;
    }
    $wallet = (new WalletService())->summary($detailId);
    $transactions = (new WalletService())->transactions($detailId, 10);
    $documents = Db::all(
        'SELECT d.id, t.name AS document_type FROM vendor_documents d
         JOIN document_types t ON t.id = d.document_type_id WHERE d.vendor_id = ?',
        [$detailId]
    );
?>
    <p><a href="<?= e(admin_url('vendors')) ?>">← Back to vendors</a></p>
    <h1><?= e($vendor['business_name']) ?></h1>
    <p class="lead"><?= chip($vendor['status']) ?> · <?= e($vendor['water_source']) ?> · <?= (int) $vendor['is_online'] === 1 ? 'Online' : 'Offline' ?></p>

    <div class="grid grid-3">
        <div class="card">
            <div class="stat-label">Wallet balance</div>
            <div class="stat-value"><?= e(money($wallet['balance'])) ?></div>
            <div class="stat-note">Available <?= e(money($wallet['available'])) ?> · pending payouts <?= e(money($wallet['pending_payouts'])) ?></div>
        </div>
        <div class="card">
            <div class="stat-label">Rating</div>
            <div class="stat-value"><?= e(number_format((float) $vendor['avg_rating'], 2)) ?> / 5</div>
            <div class="stat-note"><?= e($vendor['rating_count']) ?> rating(s)</div>
        </div>
        <div class="card">
            <div class="stat-label">Last heartbeat</div>
            <div class="stat-value" style="font-size:18px"><?= e($vendor['last_heartbeat_at'] ?? 'Never') ?></div>
            <div class="stat-note"><?= e($vendor['delivery_radius_km']) ?> km delivery radius</div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:16px">
        <div class="card">
            <h2>Profile</h2>
            <dl class="kv">
                <dt>Owner</dt><dd><?= e($vendor['first_name'] . ' ' . $vendor['last_name']) ?></dd>
                <dt>Phone</dt><dd><?= e($vendor['phone']) ?></dd>
                <dt>Price per 20L</dt><dd><?= e(money($vendor['price_per_20l'])) ?></dd>
                <dt>Hours</dt><dd><?= e($vendor['opens_at'] ?? 'Always open') ?><?= $vendor['opens_at'] ? ' – ' . e($vendor['closes_at']) : '' ?></dd>
                <dt>Pin</dt><dd><?= e($vendor['base_lat'] . ', ' . $vendor['base_lng']) ?></dd>
                <dt>Payout number</dt><dd><?= e($vendor['payout_phone']) ?></dd>
            </dl>
            <p><a href="https://www.google.com/maps/search/?api=1&query=<?= e($vendor['base_lat'] . ',' . $vendor['base_lng']) ?>" target="_blank" rel="noopener">Open shop pin in Maps</a></p>
        </div>

        <div class="card">
            <h2>Documents</h2>
            <?php if ($documents === []): ?>
                <p class="empty">No documents on file.</p>
            <?php else: ?>
                <div class="doc-grid">
                    <?php foreach ($documents as $document): ?>
                        <a href="<?= e(admin_url('document', ['id' => $document['id']])) ?>" target="_blank" rel="noopener"><?= e($document['document_type']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:20px">Account controls</h2>
            <?php if (can('vendors.suspend')): ?>
                <div class="actions">
                    <?php if ($vendor['status'] === 'Approved'): ?>
                        <form method="post" action="<?= e(admin_url('vendors', ['id' => $detailId])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="vendor_suspend">
                            <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                            <input type="hidden" name="suspended" value="1">
                            <input type="hidden" name="return" value="vendors">
                            <input type="text" name="reason" placeholder="Reason (optional)" style="margin-bottom:8px">
                            <button class="btn btn-danger btn-sm" type="submit">Suspend vendor</button>
                        </form>
                    <?php elseif ($vendor['status'] === 'Suspended'): ?>
                        <form method="post" action="<?= e(admin_url('vendors', ['id' => $detailId])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="vendor_suspend">
                            <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                            <input type="hidden" name="suspended" value="0">
                            <input type="hidden" name="return" value="vendors">
                            <button class="btn btn-success btn-sm" type="submit">Reactivate vendor</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Recent wallet transactions</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Type</th><th class="num">Amount</th><th class="num">Balance after</th><th>Note</th><th>When</th></tr></thead>
                <tbody>
                <?php if ($transactions === []): ?>
                    <tr><td colspan="5" class="empty">No transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?= e($tx['type']) ?></td>
                        <td class="num"><?= e($tx['amount_display']) ?></td>
                        <td class="num"><?= e(money($tx['balance_after'])) ?></td>
                        <td><?= e($tx['note']) ?></td>
                        <td><?= e($tx['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <?php
    $status = (string) ($_GET['status'] ?? '');
    $source = (string) ($_GET['source'] ?? '');
    $search = (string) ($_GET['q'] ?? '');
    $rows = $vendors->listForAdmin($status, $source, $search);
    $sources = Db::all('SELECT name FROM water_sources ORDER BY name');
    ?>
    <h1>Vendors</h1>
    <p class="lead">Every approved, pending and suspended shop.</p>

    <form class="filter-bar" method="get" action="<?= e(admin_url('vendors')) ?>">
        <input type="hidden" name="p" value="vendors">
        <div class="field">
            <label for="q">Search</label>
            <input id="q" type="text" name="q" value="<?= e($search) ?>" placeholder="Shop, owner or phone">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <?php foreach (['Pending', 'Approved', 'Rejected', 'Suspended'] as $option): ?>
                    <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="source">Source</label>
            <select id="source" name="source">
                <option value="">All</option>
                <?php foreach ($sources as $option): ?>
                    <option value="<?= e($option['name']) ?>" <?= $source === $option['name'] ? 'selected' : '' ?>><?= e($option['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit">Filter</button>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Shop</th><th>Owner</th><th>Source</th><th class="num">Price</th><th class="num">Wallet</th><th>Rating</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="empty">No vendors match.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e($row['business_name']) ?></strong><br>
                            <small><?= (int) $row['is_online'] === 1 ? 'Online' : 'Offline' ?></small>
                        </td>
                        <td><?= e($row['owner_name']) ?><br><small><?= e($row['owner_phone']) ?></small></td>
                        <td><?= e($row['water_source']) ?></td>
                        <td class="num"><?= e(money($row['price_per_20l'])) ?></td>
                        <td class="num"><?= e(money($row['balance'] ?? 0)) ?></td>
                        <td><?= e(number_format((float) $row['avg_rating'], 1)) ?> (<?= e($row['rating_count']) ?>)</td>
                        <td><?= chip($row['status']) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('vendors', ['id' => $row['id']])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
