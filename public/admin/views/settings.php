<?php
use MajiLink\Services\SettingsService;

$settings = new SettingsService();
$rows = array_values($settings->all());
?>
<h1>Settings</h1>
<p class="lead">Platform economics and matching rules. Changes are logged with the staff account that made them.</p>

<div class="card">
    <form method="post" action="<?= e(admin_url('settings')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="settings_save">
        <input type="hidden" name="return" value="settings">
        <div class="form-grid">
            <?php foreach ($rows as $row): ?>
                <div class="field">
                    <label for="s-<?= e($row['setting_key']) ?>"><?= e(str_replace('_', ' ', $row['setting_key'])) ?></label>
                    <input id="s-<?= e($row['setting_key']) ?>" type="text"
                           name="settings[<?= e($row['setting_key']) ?>]"
                           value="<?= e($row['setting_value']) ?>">
                    <div class="hint"><?= e($row['description']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn" type="submit">Save settings</button>
    </form>
</div>
