<div class="login-card">
    <div class="login-brand">
        <span class="brand-mark">M</span>
        <div>
            <strong>MajiLink</strong>
            <div class="stat-note">Operations portal</div>
        </div>
    </div>
    <h1>Staff sign in</h1>
    <p>Admin and support accounts use email and password. The app PIN does not apply here.</p>

    <?php foreach ($flashes as $flashItem): ?>
        <div class="flash flash-<?= e($flashItem['type']) ?>"><?= e($flashItem['message']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(admin_url('login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">
        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" autocomplete="username" required autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required minlength="8">
        </div>
        <button class="btn" type="submit">Sign in</button>
    </form>
    <p class="stat-note" style="margin-top:16px">Demo: admin@majilink.co.ke · Maji12345!</p>
</div>
