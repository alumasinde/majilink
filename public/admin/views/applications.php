<?php
use MajiLink\Services\VendorService;
use MajiLink\Support\Db;

$vendors = new VendorService();
$detailId = (int) ($_GET['id'] ?? 0);

if ($detailId > 0):
    $application = Db::one(
        'SELECT v.*, s.name AS status, ws.name AS water_source, u.first_name, u.last_name, u.phone, u.email, u.id AS owner_id
         FROM vendors v
         JOIN vendor_application_statuses s ON s.id = v.application_status_id
         JOIN water_sources ws ON ws.id = v.water_source_id
         JOIN users u ON u.id = v.user_id
         WHERE v.id = ?',
        [$detailId]
    );
    if ($application === null) {
        echo '<div class="card"><p>Application not found.</p></div>';
        return;
    }
    $documents = Db::all(
        'SELECT d.id, t.name AS document_type, d.uploaded_at
         FROM vendor_documents d JOIN document_types t ON t.id = d.document_type_id
         WHERE d.vendor_id = ? ORDER BY d.id',
        [$detailId]
    );
?>
    <p><a href="<?= e(admin_url('applications')) ?>">← Back to queue</a></p>
    <h1><?= e($application['business_name']) ?></h1>
    <p class="lead"><?= chip($application['status']) ?> · submitted <?= e($application['created_at']) ?></p>

    <div class="grid grid-2">
        <div class="card">
            <h2>Applicant</h2>
            <dl class="kv">
                <dt>Name</dt><dd><?= e($application['first_name'] . ' ' . $application['last_name']) ?></dd>
                <dt>Phone</dt><dd><?= e($application['phone']) ?></dd>
                <dt>Email</dt><dd><?= e($application['email'] ?? '—') ?></dd>
                <dt>Payout number</dt><dd><?= e($application['payout_phone']) ?></dd>
            </dl>
        </div>
        <div class="card">
            <h2>Shop</h2>
            <dl class="kv">
                <dt>Water source</dt><dd><?= e($application['water_source']) ?></dd>
                <dt>Price per 20L</dt><dd><?= e(money($application['price_per_20l'])) ?></dd>
                <dt>Delivery radius</dt><dd><?= e($application['delivery_radius_km']) ?> km</dd>
                <dt>Hours</dt><dd><?= e($application['opens_at'] ?? 'Always open') ?><?= $application['opens_at'] ? ' – ' . e($application['closes_at']) : '' ?></dd>
                <dt>Shop pin</dt>
                <dd>
                    <?= e($application['base_lat']) ?>, <?= e($application['base_lng']) ?>
                    <br>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?= e($application['base_lat'] . ',' . $application['base_lng']) ?>" target="_blank" rel="noopener">Open in Maps</a>
                    <?php if ($application['base_note']): ?><br><small><?= e($application['base_note']) ?></small><?php endif; ?>
                </dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <h2>Documents</h2>
        <?php if ($documents === []): ?>
            <p class="empty">No documents uploaded yet.</p>
        <?php else: ?>
            <div class="doc-grid">
                <?php foreach ($documents as $document): ?>
                    <a href="<?= e(admin_url('document', ['id' => $document['id']])) ?>" target="_blank" rel="noopener">
                        <strong><?= e($document['document_type']) ?></strong>
                        <div class="stat-note">Uploaded <?= e($document['uploaded_at']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($application['status'] === 'Pending' && can('vendors.review')): ?>
        <div class="card">
            <h2>Decision</h2>
            <div class="grid grid-2">
                <form method="post" action="<?= e(admin_url('applications', ['id' => $detailId])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="vendor_review">
                    <input type="hidden" name="decision" value="approve">
                    <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                    <input type="hidden" name="return" value="applications">
                    <p>Approving adds the vendor role and notifies the applicant.</p>
                    <button class="btn btn-success" type="submit">Approve vendor</button>
                </form>
                <form method="post" action="<?= e(admin_url('applications', ['id' => $detailId])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="vendor_review">
                    <input type="hidden" name="decision" value="reject">
                    <input type="hidden" name="id" value="<?= (int) $detailId ?>">
                    <input type="hidden" name="return" value="applications">
                    <div class="field">
                        <label for="reason">Rejection reason</label>
                        <textarea id="reason" name="reason" required maxlength="255" placeholder="Tell the vendor what to fix."></textarea>
                    </div>
                    <button class="btn btn-danger" type="submit">Reject application</button>
                </form>
            </div>
        </div>
    <?php elseif ($application['rejection_reason']): ?>
        <div class="card">
            <h2>Rejection reason</h2>
            <p><?= e($application['rejection_reason']) ?></p>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php
    $status = (string) ($_GET['status'] ?? 'Pending');
    $rows = $vendors->listForAdmin($status);
    ?>
    <h1>Vendor applications</h1>
    <p class="lead">Approve or reject shops. Approval adds the vendor role and unlocks vendor mode in the app.</p>

    <div class="tabs">
        <?php foreach (['Pending', 'Approved', 'Rejected', 'Suspended', ''] as $tab): ?>
            <a class="<?= $status === $tab ? 'active' : '' ?>" href="<?= e(admin_url('applications', ['status' => $tab])) ?>">
                <?= e($tab === '' ? 'All' : $tab) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Shop</th><th>Owner</th><th>Source</th><th class="num">Price</th><th>Status</th><th>Submitted</th><th></th></tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="empty">Nothing here.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e($row['business_name']) ?></strong></td>
                        <td><?= e($row['owner_name']) ?><br><small><?= e($row['owner_phone']) ?></small></td>
                        <td><?= e($row['water_source']) ?></td>
                        <td class="num"><?= e(money($row['price_per_20l'])) ?></td>
                        <td><?= chip($row['status']) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('applications', ['id' => $row['id']])) ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
