<?php
use MajiLink\Services\OtpChannelService;

$service = new OtpChannelService();
$channels = $service->channels();
$fieldHints = [
    'email'    => ['from' => 'Sender address', 'from_name' => 'Sender name'],
    'sms'      => ['username' => 'Africa\'s Talking username', 'api_key' => 'API key', 'sender_id' => 'Sender ID (optional)'],
    'whatsapp' => ['access_token' => 'Permanent access token', 'phone_number_id' => 'Phone number ID', 'template_name' => 'Template name', 'template_lang' => 'Template language'],
];
?>
<h1>Verification channels</h1>
<p class="lead">
    Email is enabled by default. A channel can only be switched on after a successful test send, and the last enabled
    channel cannot be switched off. Credentials are encrypted with libsodium and never shown back.
</p>

<?php foreach ($channels as $channel): ?>
    <div class="card">
        <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start">
            <div>
                <h2 style="margin-bottom:4px"><?= e($channel['name']) ?> <span class="stat-note">(<?= e($channel['code']) ?>)</span></h2>
                <p class="stat-note" style="margin:0">
                    Provider <strong><?= e($channel['provider']) ?></strong> ·
                    Priority <?= e($channel['priority']) ?> ·
                    Last test:
                    <?php if ($channel['last_tested_at'] === null): ?>
                        never
                    <?php elseif ((int) $channel['last_test_ok'] === 1): ?>
                        passed <?= e($channel['last_tested_at']) ?>
                    <?php else: ?>
                        failed <?= e($channel['last_tested_at']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="actions">
                <?= (int) $channel['is_enabled'] === 1 ? chip('Enabled') : chip('Disabled') ?>
                <form method="post" action="<?= e(admin_url('channels')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="channel_toggle">
                    <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                    <input type="hidden" name="enabled" value="<?= (int) $channel['is_enabled'] === 1 ? '0' : '1' ?>">
                    <input type="hidden" name="return" value="channels">
                    <button class="btn <?= (int) $channel['is_enabled'] === 1 ? 'btn-ghost' : 'btn-success' ?> btn-sm" type="submit">
                        <?= (int) $channel['is_enabled'] === 1 ? 'Disable' : 'Enable' ?>
                    </button>
                </form>
                <form method="post" action="<?= e(admin_url('channels')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="channel_priority">
                    <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                    <input type="hidden" name="return" value="channels">
                    <input type="number" name="priority" value="<?= (int) $channel['priority'] ?>" min="1" max="99" style="width:80px">
                    <button class="btn btn-ghost btn-sm" type="submit">Set priority</button>
                </form>
            </div>
        </div>

        <div class="grid grid-2" style="margin-top:14px">
            <form method="post" action="<?= e(admin_url('channels')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="channel_save">
                <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                <input type="hidden" name="return" value="channels">
                <div class="field">
                    <label for="provider-<?= (int) $channel['id'] ?>">Provider</label>
                    <select id="provider-<?= (int) $channel['id'] ?>" name="provider">
                        <?php foreach ($channel['providers'] as $provider): ?>
                            <option value="<?= e($provider) ?>" <?= $channel['provider'] === $provider ? 'selected' : '' ?>><?= e($provider) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php foreach (($fieldHints[$channel['code']] ?? []) as $field => $label): ?>
                    <div class="field">
                        <label for="<?= e($channel['code'] . '-' . $field) ?>"><?= e($label) ?></label>
                        <input id="<?= e($channel['code'] . '-' . $field) ?>" type="password" name="config[<?= e($field) ?>]"
                               placeholder="<?= in_array($field, $channel['config_fields'], true) ? 'Stored — leave blank to keep' : 'Not set' ?>"
                               autocomplete="off">
                    </div>
                <?php endforeach; ?>
                <?php if ($channel['config_masked'] !== []): ?>
                    <p class="hint">Stored fields: <?= e(implode(', ', $channel['config_masked'])) ?></p>
                <?php endif; ?>
                <button class="btn" type="submit">Save credentials</button>
            </form>

            <form method="post" action="<?= e(admin_url('channels')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="channel_test">
                <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                <input type="hidden" name="return" value="channels">
                <div class="field">
                    <label for="test-<?= (int) $channel['id'] ?>">Send a test code to</label>
                    <input id="test-<?= (int) $channel['id'] ?>" type="text" name="destination" required
                           placeholder="<?= $channel['code'] === 'email' ? 'you@example.com' : '+254712345678' ?>">
                </div>
                <button class="btn btn-ghost" type="submit">Send test</button>
                <p class="hint">A save resets the test status, so test again before enabling.</p>
            </form>
        </div>
    </div>
<?php endforeach; ?>
