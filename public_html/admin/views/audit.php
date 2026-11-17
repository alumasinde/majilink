<?php
use MajiLink\Services\AuditService;
$search = (string) ($_GET['q'] ?? '');
$rows = (new AuditService())->recent(150, $search);
?>
<h1>Audit log</h1>
<p class="lead">Every administrative change is recorded with the staff account, IP address and details.</p>

<form class="filter-bar" method="get" action="<?= e(admin_url('audit')) ?>">
    <input type="hidden" name="p" value="audit">
    <div class="field">
        <label for="q">Search</label>
        <input id="q" type="text" name="q" value="<?= e($search) ?>" placeholder="Action, entity or staff name">
    </div>
    <button class="btn" type="submit">Search</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>When</th><th>Staff</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="empty">No audit entries yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><?= e($row['staff_name'] ?? 'system') ?></td>
                    <td><?= e($row['action']) ?></td>
                    <td><?= e($row['entity']) ?><?= $row['entity_id'] ? ' #' . e($row['entity_id']) : '' ?></td>
                    <td><?= e($row['ip'] ?? '—') ?></td>
                    <td><small><?= e($row['details_json'] ?? '') ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
