<div class="card">
    <h1>Not allowed</h1>
    <p>Your account does not have the <strong><?= e($permission) ?></strong> permission, so this page is hidden.</p>
    <p><a class="btn btn-ghost" href="<?= e(admin_url('dashboard')) ?>">Back to dashboard</a></p>
</div>
