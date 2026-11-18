<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use MajiLink\Http\HttpException;
use MajiLink\Middleware\Authenticator;
use MajiLink\Services\AuditService;
use MajiLink\Services\NotificationService;
use MajiLink\Services\OrderService;
use MajiLink\Services\OtpChannelService;
use MajiLink\Services\PaymentService;
use MajiLink\Services\PayoutService;
use MajiLink\Services\ReportService;
use MajiLink\Services\RoleService;
use MajiLink\Services\SettingsService;
use MajiLink\Services\UserAdminService;
use MajiLink\Services\VendorService;
use MajiLink\Support\Config;
use MajiLink\Support\Db;
use MajiLink\Support\Hashing;
use MajiLink\Support\RateLimiter;

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
]);
session_start();

// ---------------------------------------------------------------------------
// Small view helpers
// ---------------------------------------------------------------------------
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_url(string $page = 'dashboard', array $params = []): string
{
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    $path = $page === 'dashboard' ? '/admin/' : '/admin/' . rawurlencode($page);
    return $params === [] ? $path : $path . '?' . http_build_query($params);
}

function redirect_to(string $page, array $params = []): never
{
    header('Location: ' . admin_url($page, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function assert_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Session expired. Go back and try again.');
    }
}

function money(mixed $value): string
{
    return 'KES ' . number_format((float) $value, 2);
}

function chip(string $status): string
{
    $map = [
        'pending' => 'amber', 'accepted' => 'blue', 'on the way' => 'teal',
        'delivered' => 'green', 'cancelled' => 'red', 'declined' => 'red',
        'expired' => 'red', 'approved' => 'green', 'rejected' => 'red',
        'suspended' => 'red', 'paid' => 'green', 'unpaid' => 'grey',
        'failed' => 'red', 'refunded' => 'amber', 'requested' => 'amber',
    ];
    $tone = $map[strtolower($status)] ?? 'grey';
    return '<span class="chip chip-' . $tone . '">' . e($status) . '</span>';
}

function can(string $permission): bool
{
    return Authenticator::can($permission);
}

function current_user(): ?array
{
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }
    $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['admin_user_id']]);
    return $user ?: null;
}

function audit(string $action, string $entity, ?int $entityId = null, array $details = []): void
{
    (new AuditService())->record(
        Authenticator::id(),
        $action,
        $entity,
        $entityId,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null
    );
}

function render(string $view, array $data = []): never
{
    $view = preg_replace('/\.php$/', '', $view) ?? $view;
    $viewPath = __DIR__ . '/views/' . $view . '.php';
    if (!is_file($viewPath)) {
        http_response_code(500);
        exit('Missing view: ' . e($view));
    }
    extract($data, EXTR_SKIP);
    $flashes = take_flashes();
    $staff = Authenticator::user();
    ob_start();
    require $viewPath;
    $content = (string) ob_get_clean();
    require __DIR__ . '/views/layout.php';
    exit;
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------
$page = (string) ($_GET['p'] ?? 'dashboard');
$user = current_user();

if ($user !== null) {
    try {
        Authenticator::setSessionUser((int) $user['id']);
    } catch (HttpException $e) {
        unset($_SESSION['admin_user_id']);
        $user = null;
    }
}

$action = (string) ($_POST['action'] ?? '');
if ($action !== '') {
    assert_csrf();
    if ($user === null && $action !== 'login') {
        redirect_to('login');
    }
    try {
        handle_action($action);
    } catch (HttpException $e) {
        flash('error', $e->getMessage());
        redirect_to((string) ($_POST['return'] ?? 'dashboard'));
    } catch (\Throwable $e) {
        flash('error', Config::get('debug') ? $e->getMessage() : 'Something went wrong.');
        redirect_to((string) ($_POST['return'] ?? 'dashboard'));
    }
}

if ($page === 'login') {
    if ($user !== null) {
        redirect_to('dashboard');
    }
    render_login();
    exit;
}

if ($page === 'logout') {
    assert_csrf();
    unset($_SESSION['admin_user_id']);
    session_regenerate_id(true);
    redirect_to('login');
}

if ($user === null) {
    redirect_to('login');
}

$pageMap = [
    'dashboard'    => ['reports.view', 'dashboard.php'],
    'applications' => ['vendors.review', 'applications.php'],
    'vendors'      => ['vendors.view', 'vendors.php'],
    'customers'    => ['users.view', 'customers.php'],
    'staff'        => ['users.manage', 'staff.php'],
    'orders'       => ['orders.view_all', 'orders.php'],
    'payments'     => ['payments.view', 'payments.php'],
    'payouts'      => ['payouts.process', 'payouts.php'],
    'reports'      => ['reports.view', 'reports.php'],
    'settings'     => ['settings.manage', 'settings.php'],
    'channels'     => ['settings.otp_channels', 'channels.php'],
    'lookups'      => ['settings.manage', 'lookups.php'],
    'roles'        => ['roles.manage', 'roles.php'],
    'audit'        => ['audit.view', 'audit.php'],
    'broadcast'    => ['notifications.broadcast', 'broadcast.php'],
];

if ($page === 'document') {
    if (!can('vendors.review')) {
        http_response_code(403);
        exit('Forbidden');
    }
    stream_document((int) ($_GET['id'] ?? 0));
}

if ($page === 'export') {
    if (!can('reports.view')) {
        http_response_code(403);
        exit('Forbidden');
    }
    export_csv((string) ($_GET['type'] ?? 'orders'), (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
}

if (!isset($pageMap[$page])) {
    http_response_code(404);
    $page = 'dashboard';
}

[$permission, $view] = $pageMap[$page];
if (!can($permission)) {
    http_response_code(403);
    render('forbidden', ['permission' => $permission]);
}

render($view);
exit;

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
function handle_action(string $action): void
{
    switch ($action) {
        case 'login':
            do_login();
            return;

        case 'vendor_review':
            require_permission('vendors.review');
            (new VendorService())->review(
                (int) $_POST['id'],
                Authenticator::id(),
                (string) $_POST['decision'],
                $_POST['reason'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Application ' . ($_POST['decision'] === 'approve' ? 'approved' : 'rejected') . '.');
            break;

        case 'vendor_suspend':
            require_permission('vendors.suspend');
            (new VendorService())->setSuspended(
                (int) $_POST['id'],
                Authenticator::id(),
                !empty($_POST['suspended']),
                $_POST['reason'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Vendor updated.');
            break;

        case 'user_toggle_active':
            require_permission('users.manage');
            (new UserAdminService())->setActive(
                (int) $_POST['id'],
                !empty($_POST['active']),
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Account updated.');
            break;

        case 'user_revoke_devices':
            require_permission('users.manage');
            $count = (new UserAdminService())->revokeDevices((int) $_POST['id'], Authenticator::id(), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', $count . ' device(s) signed out.');
            break;

        case 'order_override':
            require_permission('orders.override_status');
            (new OrderService())->override(
                (int) $_POST['id'],
                Authenticator::id(),
                (string) $_POST['status'],
                (string) ($_POST['reason'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Order status overridden.');
            break;

        case 'payment_mark_paid':
            require_permission('payments.view');
            (new PaymentService())->markPaidManually(
                (int) $_POST['id'],
                Authenticator::id(),
                $_POST['mpesa_receipt'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Payment marked paid.');
            break;

        case 'payment_refund':
            require_permission('payments.view');
            (new PaymentService())->refund(
                (int) $_POST['id'],
                Authenticator::id(),
                $_POST['reason'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Payment refunded.');
            break;

        case 'payout_approve':
            require_permission('payouts.process');
            (new PayoutService())->approve((int) $_POST['id'], Authenticator::id(), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', 'Payout approved.');
            break;

        case 'payout_reject':
            require_permission('payouts.process');
            (new PayoutService())->reject((int) $_POST['id'], Authenticator::id(), (string) ($_POST['reason'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', 'Payout rejected.');
            break;

        case 'payout_paid':
            require_permission('payouts.process');
            (new PayoutService())->markPaid((int) $_POST['id'], Authenticator::id(), (string) ($_POST['mpesa_receipt'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', 'Payout marked paid.');
            break;

        case 'settings_save':
            require_permission('settings.manage');
            $values = [];
            foreach (($_POST['settings'] ?? []) as $key => $value) {
                $values[(string) $key] = (string) $value;
            }
            (new SettingsService())->update($values, Authenticator::id(), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', 'Settings saved.');
            break;

        case 'channel_save':
            require_permission('settings.otp_channels');
            $fields = [];
            foreach (($_POST['config'] ?? []) as $key => $value) {
                $fields[(string) $key] = (string) $value;
            }
            (new OtpChannelService())->save(
                (int) $_POST['id'],
                (string) $_POST['provider'],
                $fields,
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Channel credentials saved. Send a test before enabling.');
            break;

        case 'channel_test':
            require_permission('settings.otp_channels');
            $result = (new OtpChannelService())->sendTest(
                (int) $_POST['id'],
                (string) $_POST['destination'],
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'channel_toggle':
            require_permission('settings.otp_channels');
            (new OtpChannelService())->toggle(
                (int) $_POST['id'],
                !empty($_POST['enabled']),
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Channel updated.');
            break;

        case 'channel_priority':
            require_permission('settings.otp_channels');
            (new OtpChannelService())->priority((int) $_POST['id'], (int) $_POST['priority'], Authenticator::id(), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', 'Priority updated.');
            break;

        case 'lookup_add':
            require_permission('settings.manage');
            $table = lookup_table((string) $_POST['lookup']);
            $name = trim((string) $_POST['name']);
            if ($name === '') {
                throw HttpException::validation(['name' => 'Enter a name.']);
            }
            Db::run("INSERT IGNORE INTO {$table} (name) VALUES (?)", [$name]);
            audit('lookups.add', $table, null, ['name' => $name]);
            flash('success', 'Lookup value added.');
            break;

        case 'lookup_rename':
            require_permission('settings.manage');
            $table = lookup_table((string) $_POST['lookup']);
            $id = (int) $_POST['id'];
            $name = trim((string) $_POST['name']);
            if ($name === '') {
                throw HttpException::validation(['name' => 'Enter a name.']);
            }
            Db::update($table, ['name' => $name], 'id = ?', [$id]);
            audit('lookups.rename', $table, $id, ['name' => $name]);
            flash('success', 'Lookup value renamed.');
            break;

        case 'role_create':
            require_permission('roles.manage');
            (new RoleService())->createRole(
                (string) $_POST['name'],
                $_POST['description'] ?? null,
                array_map('intval', $_POST['permissions'] ?? []),
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Role created.');
            break;

        case 'role_permissions':
            require_permission('roles.manage');
            (new RoleService())->updatePermissions(
                (int) $_POST['id'],
                array_map('intval', $_POST['permissions'] ?? []),
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'Permissions updated.');
            break;

        case 'roles_assign':
            require_permission('roles.manage');
            (new RoleService())->assignRoles(
                (int) $_POST['user_id'],
                array_map('intval', $_POST['roles'] ?? []),
                Authenticator::id(),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            flash('success', 'User roles updated.');
            break;

        case 'staff_create':
            require_permission('users.manage');
            (new UserAdminService())->createStaff($_POST, Authenticator::id(), $_SERVER['REMOTE_ADDR'] ?? null);
            flash('success', 'Staff account created.');
            break;

        case 'broadcast_send':
            require_permission('notifications.broadcast');
            $audience = (string) $_POST['audience'];
            $roles = match ($audience) {
                'customers' => ['customer'],
                'vendors'   => ['vendor'],
                default     => ['customer', 'vendor'],
            };
            $count = (new NotificationService())->broadcast($roles, (string) $_POST['title'], (string) $_POST['body']);
            audit('notifications.broadcast', 'notifications', null, ['audience' => $audience]);
            flash('success', 'Sent to ' . $count . ' user(s).');
            break;

        default:
            throw HttpException::badRequest('Unknown action.');
    }

    redirect_to((string) ($_POST['return'] ?? 'dashboard'));
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        throw HttpException::forbidden('You do not have permission to do that.');
    }
}

function lookup_table(string $key): string
{
    $tables = [
        'water_sources'              => 'water_sources',
        'document_types'             => 'document_types',
        'frequencies'                => 'frequencies',
        'order_statuses'             => 'order_statuses',
    ];
    if (!isset($tables[$key])) {
        throw HttpException::badRequest('That lookup cannot be edited.');
    }
    return $tables[$key];
}

function do_login(): void
{
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!RateLimiter::attempt('admin.login.ip', $ip, 10, 300) || !RateLimiter::attempt('admin.login.email', $email, 5, 300)) {
        flash('error', 'Too many attempts. Wait a few minutes.');
        redirect_to('login');
    }

    $user = Db::one(
        "SELECT u.* FROM users u
         JOIN user_roles ur ON ur.user_id = u.id
         JOIN roles r ON r.id = ur.role_id
         WHERE u.email = ? AND u.is_active = 1 AND r.name IN ('admin', 'support')
         LIMIT 1",
        [$email]
    );
    if ($user === null || !Hashing::verify($password, $user['password_hash'])) {
        flash('error', 'Those details do not match a staff account.');
        redirect_to('login');
    }

    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int) $user['id'];
    Db::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
    (new AuditService())->record((int) $user['id'], 'admin.login', 'users', (int) $user['id'], [], $ip);
    flash('success', 'Welcome back, ' . $user['first_name'] . '.');
    redirect_to('dashboard');
}

function render_login(): void
{
    $flashes = take_flashes();
    $staff = null;
    ob_start();
    require __DIR__ . '/views/login.php';
    $content = (string) ob_get_clean();
    $bare = true;
    require __DIR__ . '/views/layout.php';
    exit;
}

function stream_document(int $documentId): never
{
    $document = (new VendorService())->documentForAdmin($documentId);
    $path = (string) Config::get('upload_dir') . '/' . $document['file_path'];
    if (!is_file($path)) {
        http_response_code(404);
        exit('File missing');
    }
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    header('Content-Type: ' . $finfo->file($path));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . basename($document['file_path']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function export_csv(string $type, string $from, string $to): never
{
    $reports = new ReportService();
    $from = $from !== '' ? $from : gmdate('Y-m-d', strtotime('-30 days'));
    $to = $to !== '' ? $to : gmdate('Y-m-d');
    $rows = match ($type) {
        'vendors' => $reports->vendorPerformance($from, $to),
        'areas'   => $reports->ordersByArea($from, $to),
        default   => $reports->ordersByDay($from, $to),
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="majilink-' . $type . '-' . gmdate('Ymd') . '.csv"');
    echo $reports->toCsv($rows);
    exit;
}
