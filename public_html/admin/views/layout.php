<?php
use MajiLink\Middleware\Authenticator;

/** @var string $content */
/** @var array $flashes */
/** @var array|null $staff */
$current = $_GET['p'] ?? 'dashboard';
$bare = $bare ?? false;
$nav = [
    'Overview' => [
        ['dashboard', 'Dashboard', 'reports.view'],
    ],
    'Vendors' => [
        ['applications', 'Applications', 'vendors.review'],
        ['vendors', 'Vendors', 'vendors.view'],
    ],
    'People' => [
        ['customers', 'Customers', 'users.view'],
        ['staff', 'Staff', 'users.manage'],
    ],
    'Operations' => [
        ['orders', 'Orders', 'orders.view_all'],
        ['payments', 'Payments', 'payments.view'],
        ['payouts', 'Payouts', 'payouts.process'],
    ],
    'Insight' => [
        ['reports', 'Reports', 'reports.view'],
        ['audit', 'Audit log', 'audit.view'],
    ],
    'Configuration' => [
        ['settings', 'Settings', 'settings.manage'],
        ['channels', 'Verification channels', 'settings.otp_channels'],
        ['lookups', 'Lookups', 'settings.manage'],
        ['roles', 'Roles and permissions', 'roles.manage'],
    ],
    'Communication' => [
        ['broadcast', 'Broadcast', 'notifications.broadcast'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MajiLink Admin</title>
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if ($bare): ?>
    <?= $content ?>
<?php else: ?>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand-mark">M</span>
                <span class="brand-text">MajiLink<small>Admin</small></span>
            </div>
            <nav>
                <?php foreach ($nav as $group => $items): ?>
                    <?php
                    $visible = array_filter($items, static fn ($item) => can($item[2]));
                    if ($visible === []) {
                        continue;
                    }
                    ?>
                    <p class="nav-group"><?= e($group) ?></p>
                    <?php foreach ($visible as [$pageKey, $label, $permission]): ?>
                        <a class="nav-link <?= $current === $pageKey ? 'active' : '' ?>" href="<?= e(admin_url($pageKey)) ?>">
                            <?= e($label) ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="main">
            <header class="topbar">
                <div class="topbar-title"><?= e(ucfirst($current === 'dashboard' ? 'Overview' : $current)) ?></div>
                <div class="topbar-user">
                    <span><?= e(trim($staff['first_name'] . ' ' . $staff['last_name'])) ?></span>
                    <span class="role-pill"><?= e(implode(', ', Authenticator::roles())) ?></span>
                    <form method="post" action="<?= e(admin_url('logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-ghost" type="submit">Sign out</button>
                    </form>
                </div>
            </header>

            <div class="content">
                <?php foreach ($flashes as $flashItem): ?>
                    <div class="flash flash-<?= e($flashItem['type']) ?>"><?= e($flashItem['message']) ?></div>
                <?php endforeach; ?>
                <?= $content ?>
            </div>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
