<?php
use MajiLink\Support\Db;

$lookups = [
    'water_sources'  => ['label' => 'Water sources', 'table' => 'water_sources'],
    'document_types' => ['label' => 'Document types', 'table' => 'document_types'],
    'frequencies'    => ['label' => 'Frequencies', 'table' => 'frequencies'],
];
$active = (string) ($_GET['lookup'] ?? 'water_sources');
if (!isset($lookups[$active])) {
    $active = 'water_sources';
}
$rows = Db::all('SELECT * FROM ' . $lookups[$active]['table'] . ' ORDER BY id');
?>
<h1>Lookups</h1>
<p class="lead">Reference values used across the platform. Renaming is safe; existing records keep their link.</p>

<div class="tabs">
    <?php foreach ($lookups as $key => $meta): ?>
        <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e(admin_url('lookups', ['lookup' => $key])) ?>"><?= e($meta['label']) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Name</th><th>Extra</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['id']) ?></td>
                    <td>
                        <form class="actions" method="post" action="<?= e(admin_url('lookups', ['lookup' => $active])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="lookup_rename">
                            <input type="hidden" name="lookup" value="<?= e($active) ?>">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="return" value="lookups">
                            <input type="text" name="name" value="<?= e($row['name']) ?>" required style="max-width:280px">
                            <button class="btn btn-ghost btn-sm" type="submit">Rename</button>
                        </form>
                    </td>
                    <td><?= isset($row['interval_days']) ? e($row['interval_days']) . ' days' : '—' ?></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 style="margin-top:20px">Add a value</h2>
    <form class="actions" method="post" action="<?= e(admin_url('lookups', ['lookup' => $active])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="lookup_add">
        <input type="hidden" name="lookup" value="<?= e($active) ?>">
        <input type="hidden" name="return" value="lookups">
        <input type="text" name="name" placeholder="New value" required style="max-width:280px">
        <button class="btn" type="submit">Add</button>
    </form>
</div>
