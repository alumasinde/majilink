<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use MajiLink\Support\Config;
use MajiLink\Support\Db;
use MajiLink\Support\Env;
use MajiLink\Support\Hashing;

/**
 * Idempotent seeder. Reference data is always seeded; demo accounts, vendors,
 * pins and orders are local-development only.
 *
 * Modes:
 *   php database/seeders/seed.php            defaults + demo (unless APP_ENV=production)
 *   php database/seeders/seed.php --defaults reference data + optional admin only
 *
 * The first admin is created from SEED_ADMIN_* environment values. Demo
 * accounts require SEED_DEMO_PASSWORD; nothing is hardcoded or printed.
 */

function seed_lookup(string $table, array $names): array
{
    $ids = [];
    foreach ($names as $name) {
        $existing = Db::value("SELECT id FROM {$table} WHERE name = ?", [$name]);
        if ($existing === null) {
            Db::insert($table, ['name' => $name]);
            $existing = Db::pdo()->lastInsertId();
        }
        $ids[$name] = (int) $existing;
    }
    return $ids;
}

function seed_user(string $first, string $last, string $phone, ?string $email, string $password, array $roles): int
{
    $existing = Db::value('SELECT id FROM users WHERE phone = ?', [$phone]);
    if ($existing === null) {
        $id = Db::insert('users', [
            'first_name'        => $first,
            'last_name'         => $last,
            'phone'             => $phone,
            'email'             => $email,
            'password_hash'     => Hashing::make($password),
            'is_active'         => 1,
            'phone_verified_at' => gmdate('Y-m-d H:i:s'),
            'email_verified_at' => $email !== null ? gmdate('Y-m-d H:i:s') : null,
        ]);
    } else {
        $id = (int) $existing;
    }
    foreach ($roles as $role) {
        $roleId = (int) Db::value('SELECT id FROM roles WHERE name = ?', [$role]);
        Db::run('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $roleId]);
    }
    return $id;
}

// ---------------------------------------------------------------------------
// Mode
// ---------------------------------------------------------------------------
$arguments = $argv ?? [];
$isProduction = Config::get('env') === 'production';
$wantsDemo = in_array('--demo', $arguments, true);
$defaultsOnly = $isProduction || in_array('--defaults', $arguments, true);

if ($isProduction && $wantsDemo) {
    fwrite(STDERR, "Refusing to seed demo data while APP_ENV=production.\n");
    return;
}
if ($wantsDemo) {
    $defaultsOnly = false;
}

// ---------------------------------------------------------------------------
// Roles and permissions
// ---------------------------------------------------------------------------
$roles = seed_lookup('roles', ['customer', 'vendor', 'admin', 'support']);

$permissionNames = [
    'orders.create'          => 'Place orders',
    'orders.view_own'        => 'View own orders',
    'orders.view_all'        => 'View every order',
    'orders.override_status' => 'Override an order status',
    'orders.accept'          => 'Accept or decline vendor orders',
    'vendors.toggle_online'  => 'Go online or offline',
    'vendors.apply'          => 'Apply to become a vendor',
    'vendors.view'           => 'View vendor records',
    'vendors.review'         => 'Review vendor applications',
    'vendors.suspend'        => 'Suspend or reactivate vendors',
    'users.view'             => 'View users',
    'users.manage'           => 'Manage users and devices',
    'payments.view'          => 'View payments',
    'payouts.process'        => 'Process vendor payouts',
    'payouts.request'        => 'Request a payout',
    'wallet.view'            => 'View vendor wallet',
    'reports.view'           => 'View reports',
    'settings.manage'        => 'Manage settings and lookups',
    'settings.otp_channels'  => 'Manage verification channels',
    'roles.manage'           => 'Manage roles and permissions',
    'audit.view'             => 'View the audit log',
    'notifications.broadcast'=> 'Broadcast notifications',
    'locations.manage'       => 'Manage saved delivery pins',
    'ratings.create'         => 'Rate a delivered order',
];
$permissionIds = [];
foreach ($permissionNames as $name => $description) {
    $existing = Db::value('SELECT id FROM permissions WHERE name = ?', [$name]);
    if ($existing === null) {
        $existing = Db::insert('permissions', ['name' => $name, 'description' => $description]);
    }
    $permissionIds[$name] = (int) $existing;
}

$grants = [
    // A customer may apply to become a vendor before holding the vendor role.
    'customer' => ['orders.create', 'orders.view_own', 'locations.manage', 'ratings.create', 'vendors.apply'],
    'vendor'   => ['vendors.apply', 'vendors.toggle_online', 'orders.accept', 'orders.view_own', 'wallet.view', 'payouts.request'],
    'support'  => ['orders.view_all', 'users.view', 'payments.view', 'reports.view', 'audit.view', 'vendors.view'],
    'admin'    => array_keys($permissionNames),
];
foreach ($grants as $roleName => $names) {
    foreach ($names as $permission) {
        Db::run(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
            [$roles[$roleName], $permissionIds[$permission]]
        );
    }
}

// ---------------------------------------------------------------------------
// Lookups
// ---------------------------------------------------------------------------
$waterSources = seed_lookup('water_sources', ['Borehole', 'Purified', 'Tap']);
$vendorStatuses = seed_lookup('vendor_application_statuses', ['Pending', 'Approved', 'Rejected', 'Suspended']);
seed_lookup('document_types', ['National ID front', 'National ID back', 'Selfie', 'Shop photo', 'Water permit']);
$orderStatuses = seed_lookup('order_statuses', ['Pending', 'Accepted', 'On the way', 'Delivered', 'Declined', 'Cancelled', 'Expired']);
$paymentMethods = seed_lookup('payment_methods', ['Cash', 'M-Pesa']);
$paymentStatuses = seed_lookup('payment_statuses', ['Unpaid', 'Pending', 'Paid', 'Failed', 'Refunded']);
seed_lookup('transaction_types', ['Order earning', 'Commission', 'Payout', 'Adjustment', 'Refund']);
$payoutStatuses = seed_lookup('payout_statuses', ['Requested', 'Approved', 'Paid', 'Rejected']);

$orderStatusMeta = [
    'Pending'    => [1, 0],
    'Accepted'   => [2, 0],
    'On the way' => [3, 0],
    'Delivered'  => [4, 1],
    'Declined'   => [5, 1],
    'Cancelled'  => [6, 1],
    'Expired'    => [7, 1],
];
foreach ($orderStatusMeta as $name => [$sort, $final]) {
    Db::update('order_statuses', ['sort_order' => $sort, 'is_final' => $final], 'id = ?', [$orderStatuses[$name]]);
}

$frequencies = [];
foreach ([['Weekly', 7], ['Every 2 weeks', 14], ['Monthly', 30]] as [$name, $days]) {
    $existing = Db::value('SELECT id FROM frequencies WHERE name = ?', [$name]);
    if ($existing === null) {
        $existing = Db::insert('frequencies', ['name' => $name, 'interval_days' => $days]);
    }
    $frequencies[$name] = (int) $existing;
}

// ---------------------------------------------------------------------------
// Verification channels
// ---------------------------------------------------------------------------
$purposes = [];
foreach (['register' => 'Register', 'reset_password' => 'Reset password', 'change_phone' => 'Change phone'] as $code => $name) {
    $existing = Db::value('SELECT id FROM otp_purposes WHERE code = ?', [$code]);
    if ($existing === null) {
        $existing = Db::insert('otp_purposes', ['code' => $code, 'name' => $name]);
    }
    $purposes[$code] = (int) $existing;
}

$channels = [];
foreach (['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $code => $name) {
    $existing = Db::value('SELECT id FROM otp_channels WHERE code = ?', [$code]);
    if ($existing === null) {
        $existing = Db::insert('otp_channels', ['code' => $code, 'name' => $name]);
    }
    $channels[$code] = (int) $existing;
}

$channelSettings = [
    'email'    => ['provider' => 'mail', 'priority' => 1, 'enabled' => 1, 'tested' => 1],
    'sms'      => ['provider' => 'africastalking', 'priority' => 2, 'enabled' => 0, 'tested' => null],
    'whatsapp' => ['provider' => 'meta_cloud', 'priority' => 3, 'enabled' => 0, 'tested' => null],
];
foreach ($channelSettings as $code => $setting) {
    $exists = Db::value('SELECT 1 FROM otp_channel_settings WHERE channel_id = ?', [$channels[$code]]);
    if ($exists !== null) {
        continue;
    }
    Db::insert('otp_channel_settings', [
        'channel_id'     => $channels[$code],
        'provider'       => $setting['provider'],
        'is_enabled'     => $setting['enabled'],
        'priority'       => $setting['priority'],
        'last_tested_at' => $setting['tested'] ? gmdate('Y-m-d H:i:s') : null,
        'last_test_ok'   => $setting['tested'],
    ]);
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------
$settings = [
    'commission_percent'        => ['10', 'Percentage MajiLink keeps from the water subtotal'],
    'default_radius_km'         => ['3', 'Default customer search radius'],
    'max_radius_km'             => ['5', 'Largest radius a customer may search'],
    'order_expiry_minutes'      => ['5', 'Minutes a vendor has to accept a pending order'],
    'delivery_base_fee'         => ['50', 'Flat delivery fee in KES'],
    'delivery_fee_per_km'       => ['10', 'Additional delivery fee per km in KES'],
    'min_quantity'              => ['1', 'Minimum jerrycans per order'],
    'max_quantity'              => ['20', 'Maximum jerrycans per order'],
    'heartbeat_timeout_minutes' => ['15', 'Mark a silent vendor offline after this long'],
];
foreach ($settings as $key => [$value, $description]) {
    Db::run(
        'INSERT INTO app_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)',
        [$key, $value, $description]
    );
}

// ---------------------------------------------------------------------------
// First admin (environment driven, never hardcoded)
// ---------------------------------------------------------------------------
$adminNotice = null;
$adminFields = [
    'SEED_ADMIN_FIRST_NAME' => trim((string) Env::get('SEED_ADMIN_FIRST_NAME', '')),
    'SEED_ADMIN_LAST_NAME'  => trim((string) Env::get('SEED_ADMIN_LAST_NAME', '')),
    'SEED_ADMIN_PHONE'      => trim((string) Env::get('SEED_ADMIN_PHONE', '')),
    'SEED_ADMIN_PASSWORD'   => (string) Env::get('SEED_ADMIN_PASSWORD', ''),
];
$adminEmail = trim((string) Env::get('SEED_ADMIN_EMAIL', ''));
$providedAdminFields = array_filter($adminFields, static fn (string $value): bool => $value !== '');
$missingAdminFields = array_keys(array_diff_key($adminFields, $providedAdminFields));

if ($missingAdminFields === []) {
    $emailOwner = $adminEmail !== '' ? Db::value('SELECT phone FROM users WHERE email = ?', [$adminEmail]) : null;
    $phoneOwner = Db::value('SELECT 1 FROM users WHERE phone = ?', [$adminFields['SEED_ADMIN_PHONE']]);

    if (mb_strlen($adminFields['SEED_ADMIN_PASSWORD']) < 8) {
        $adminNotice = 'Admin not seeded: SEED_ADMIN_PASSWORD must be at least 8 characters.';
    } elseif ($emailOwner !== null && $emailOwner !== $adminFields['SEED_ADMIN_PHONE']) {
        $adminNotice = 'Admin not seeded: SEED_ADMIN_EMAIL belongs to a different account.';
    } else {
        seed_user(
            $adminFields['SEED_ADMIN_FIRST_NAME'],
            $adminFields['SEED_ADMIN_LAST_NAME'],
            $adminFields['SEED_ADMIN_PHONE'],
            $adminEmail !== '' ? $adminEmail : null,
            $adminFields['SEED_ADMIN_PASSWORD'],
            ['admin']
        );
        $adminNotice = $phoneOwner === null
            ? 'Admin account created.'
            : 'Admin account already existed; admin role ensured and password left unchanged.';
    }
} elseif ($providedAdminFields !== []) {
    $adminNotice = 'Admin not seeded: missing ' . implode(', ', $missingAdminFields) . '.';
}

if ($defaultsOnly) {
    echo "Reference data seeded (roles, permissions, lookups, verification channels, settings).\n";
    if ($adminNotice !== null) {
        echo $adminNotice . "\n";
    }
    echo "Demo data skipped.\n";
    return;
}

// ---------------------------------------------------------------------------
// Demo data (local development only)
// ---------------------------------------------------------------------------
$demoPassword = (string) Env::get('SEED_DEMO_PASSWORD', '');
if (mb_strlen($demoPassword) < 8) {
    echo "Demo data skipped: set SEED_DEMO_PASSWORD (8+ characters) to seed demo accounts.\n";
    return;
}

$adminId = seed_user('Amos', 'Kimani', '0700000001', 'admin@majilink.co.ke', $demoPassword, ['admin']);
$supportId = seed_user('Sarah', 'Otieno', '0700000002', 'support@majilink.co.ke', $demoPassword, ['support']);
$albertId = seed_user('Albert', 'Mwangi', '0712000001', 'albert@example.com', $demoPassword, ['customer']);
$graceId = seed_user('Grace', 'Wanjiku', '0712000002', 'grace@example.com', $demoPassword, ['customer']);
seed_user('Brian', 'Wekesa', '0722000005', 'brian@example.com', $demoPassword, ['customer']);

$vendorSeeds = [
    ['Peter', 'Njoroge', '0722000001', 'peter@kilimaniwater.co.ke', 'Kilimani Pure Water', 'Purified', '60.00', -1.29200, 36.78600, 'Approved'],
    ['Mary', 'Achieng', '0722000002', 'mary@kileleshwa.co.ke', 'Kileleshwa Borehole', 'Borehole', '40.00', -1.28600, 36.78200, 'Approved'],
    ['John', 'Kariuki', '0722000003', 'john@westlandsrefill.co.ke', 'Westlands Refill Point', 'Tap', '30.00', -1.26500, 36.80400, 'Approved'],
    ['Lucy', 'Muthoni', '0722000004', 'lucy@southbaqua.co.ke', 'South B Aqua', 'Purified', '55.00', -1.31000, 36.84000, 'Approved'],
];
$vendorIds = [];
foreach ($vendorSeeds as $index => [$first, $last, $phone, $email, $business, $source, $price, $lat, $lng, $status]) {
    $userId = seed_user($first, $last, $phone, $email, $demoPassword, $status === 'Approved' ? ['customer', 'vendor'] : ['customer']);
    $existing = Db::value('SELECT id FROM vendors WHERE user_id = ?', [$userId]);
    if ($existing === null) {
        $vendorId = Db::insert('vendors', [
            'user_id'               => $userId,
            'business_name'         => $business,
            'water_source_id'       => $waterSources[$source],
            'price_per_20l'         => $price,
            'delivery_radius_km'    => '5.00',
            'base_lat'              => $lat,
            'base_lng'              => $lng,
            'base_note'             => 'Seeded demo shop (open 24 hours)',
            // NULL hours mean "always open"; real vendors set their own window.
            'opens_at'              => null,
            'closes_at'             => null,
            'payout_phone'          => $phone,
            'is_online'             => 1,
            'last_heartbeat_at'     => gmdate('Y-m-d H:i:s'),
            'application_status_id' => $vendorStatuses[$status],
            'reviewed_by'           => $adminId,
            'reviewed_at'           => gmdate('Y-m-d H:i:s'),
        ]);
    } else {
        $vendorId = (int) $existing;
    }
    $vendorIds[$business] = $vendorId;
    Db::run('INSERT IGNORE INTO vendor_wallets (vendor_id, balance) VALUES (?, 0)', [$vendorId]);
    // Keep demo shops discoverable when the seeder is re-run after the
    // heartbeat window has passed.
    if ($status === 'Approved') {
        Db::update('vendors', [
            'is_online'         => 1,
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'opens_at'          => null,
            'closes_at'         => null,
        ], 'id = ?', [$vendorId]);
    }
}

// Pending application (Brian Wekesa)
$brianId = (int) Db::value('SELECT id FROM users WHERE phone = ?', ['0722000005']);
if (Db::value('SELECT 1 FROM vendors WHERE user_id = ?', [$brianId]) === null) {
    Db::insert('vendors', [
        'user_id'               => $brianId,
        'business_name'         => 'Wekesa Water Point',
        'water_source_id'       => $waterSources['Borehole'],
        'price_per_20l'         => '35.00',
        'delivery_radius_km'    => '4.00',
        'base_lat'              => -1.30000,
        'base_lng'              => 36.79000,
        'base_note'             => 'Next to the stage',
        'opens_at'              => '06:30:00',
        'closes_at'             => '19:00:00',
        'payout_phone'          => '0722000005',
        'is_online'             => 0,
        'application_status_id' => $vendorStatuses['Pending'],
    ]);
}

// Customer pins
$pins = [
    [$albertId, "Albert's House", -1.29250, 36.78750, 'Blue gate opposite the chemist', 1],
    [$graceId, "Grace's Apartment", -1.28700, 36.78300, 'Block B, 3rd floor', 1],
];
foreach ($pins as [$userId, $alias, $lat, $lng, $note, $default]) {
    if (Db::value('SELECT 1 FROM locations WHERE user_id = ? AND alias = ?', [$userId, $alias]) === null) {
        Db::insert('locations', [
            'user_id'       => $userId,
            'alias'         => $alias,
            'lat'           => $lat,
            'lng'           => $lng,
            'landmark_note' => $note,
            'is_default'    => $default,
        ]);
    }
}

// One delivered cash order, one delivered M-Pesa order, one pending order.
$now = time();
$deliveredStatus = $orderStatuses['Delivered'];
$pendingStatus = $orderStatuses['Pending'];
$cashId = $paymentMethods['Cash'];
$mpesaId = $paymentMethods['M-Pesa'];
$paidId = $paymentStatuses['Paid'];

if (Db::value('SELECT 1 FROM orders WHERE order_no = ?', ['ML-100001']) === null) {
    $locationId = (int) Db::value('SELECT id FROM locations WHERE user_id = ? LIMIT 1', [$albertId]);
    $vendorId = $vendorIds['Kilimani Pure Water'];
    $orderId = Db::insert('orders', [
        'order_no'          => 'ML-100001',
        'customer_id'       => $albertId,
        'vendor_id'         => $vendorId,
        'location_id'       => $locationId,
        'status_id'         => $deliveredStatus,
        'quantity'          => 2,
        'unit_price'        => '60.00',
        'delivery_fee'      => '50.00',
        'total'             => '170.00',
        'commission_amount' => '12.00',
        'delivery_lat'      => -1.29250,
        'delivery_lng'      => 36.78750,
        'delivery_alias'    => "Albert's House",
        'note'              => 'Call when at the gate',
        'requested_at'      => gmdate('Y-m-d H:i:s', $now - 86400 * 2),
        'accepted_at'       => gmdate('Y-m-d H:i:s', $now - 86400 * 2 + 60),
        'delivered_at'      => gmdate('Y-m-d H:i:s', $now - 86400 * 2 + 1800),
        'expires_at'        => gmdate('Y-m-d H:i:s', $now - 86400 * 2 + 300),
    ]);
    Db::insert('order_status_history', ['order_id' => $orderId, 'status_id' => $pendingStatus, 'changed_by' => $albertId, 'note' => 'Order placed']);
    Db::insert('order_status_history', ['order_id' => $orderId, 'status_id' => $deliveredStatus, 'changed_by' => null, 'note' => 'Delivered']);
    Db::insert('payments', ['order_id' => $orderId, 'method_id' => $cashId, 'status_id' => $paidId, 'amount' => '170.00']);
    Db::insert('ratings', ['order_id' => $orderId, 'customer_id' => $albertId, 'vendor_id' => $vendorId, 'stars' => 5, 'comment' => 'Fast delivery, water was clean.']);

    $earningType = (int) Db::value('SELECT id FROM transaction_types WHERE name = ?', ['Order earning']);
    $commissionType = (int) Db::value('SELECT id FROM transaction_types WHERE name = ?', ['Commission']);
    Db::insert('wallet_transactions', ['vendor_id' => $vendorId, 'type_id' => $earningType, 'order_id' => $orderId, 'amount' => '170.00', 'balance_after' => '170.00', 'note' => 'Cash order ML-100001']);
    Db::insert('wallet_transactions', ['vendor_id' => $vendorId, 'type_id' => $commissionType, 'order_id' => $orderId, 'amount' => '-12.00', 'balance_after' => '158.00', 'note' => 'Commission on ML-100001']);
    Db::update('vendor_wallets', ['balance' => '158.00'], 'vendor_id = ?', [$vendorId]);
    Db::update('vendors', ['avg_rating' => 5.00, 'rating_count' => 1], 'id = ?', [$vendorId]);
}

if (Db::value('SELECT 1 FROM orders WHERE order_no = ?', ['ML-100002']) === null) {
    $locationId = (int) Db::value('SELECT id FROM locations WHERE user_id = ? LIMIT 1', [$graceId]);
    $vendorId = $vendorIds['Kileleshwa Borehole'];
    $orderId = Db::insert('orders', [
        'order_no'          => 'ML-100002',
        'customer_id'       => $graceId,
        'vendor_id'         => $vendorId,
        'location_id'       => $locationId,
        'status_id'         => $deliveredStatus,
        'quantity'          => 3,
        'unit_price'        => '40.00',
        'delivery_fee'      => '50.00',
        'total'             => '170.00',
        'commission_amount' => '12.00',
        'delivery_lat'      => -1.28700,
        'delivery_lng'      => 36.78300,
        'delivery_alias'    => "Grace's Apartment",
        'requested_at'      => gmdate('Y-m-d H:i:s', $now - 86400),
        'accepted_at'       => gmdate('Y-m-d H:i:s', $now - 86400 + 120),
        'delivered_at'      => gmdate('Y-m-d H:i:s', $now - 86400 + 2400),
        'expires_at'        => gmdate('Y-m-d H:i:s', $now - 86400 + 300),
    ]);
    Db::insert('order_status_history', ['order_id' => $orderId, 'status_id' => $pendingStatus, 'changed_by' => $graceId, 'note' => 'Order placed']);
    Db::insert('order_status_history', ['order_id' => $orderId, 'status_id' => $deliveredStatus, 'changed_by' => null, 'note' => 'Delivered']);
    Db::insert('payments', [
        'order_id'                   => $orderId,
        'method_id'                  => $mpesaId,
        'status_id'                  => $paidId,
        'amount'                     => '170.00',
        'phone'                      => '0712000002',
        'mpesa_checkout_request_id'  => 'ws_CO_SEED_100002',
        'mpesa_receipt'              => 'SEEDRCPT01',
    ]);
    Db::insert('ratings', ['order_id' => $orderId, 'customer_id' => $graceId, 'vendor_id' => $vendorId, 'stars' => 4, 'comment' => 'Good service.']);

    $earningType = (int) Db::value('SELECT id FROM transaction_types WHERE name = ?', ['Order earning']);
    $commissionType = (int) Db::value('SELECT id FROM transaction_types WHERE name = ?', ['Commission']);
    Db::insert('wallet_transactions', ['vendor_id' => $vendorId, 'type_id' => $earningType, 'order_id' => $orderId, 'amount' => '158.00', 'balance_after' => '158.00', 'note' => 'M-Pesa earnings for ML-100002 (net of commission)']);
    Db::update('vendor_wallets', ['balance' => '158.00'], 'vendor_id = ?', [$vendorId]);
    Db::update('vendors', ['avg_rating' => 4.00, 'rating_count' => 1], 'id = ?', [$vendorId]);
}

if (Db::value('SELECT 1 FROM orders WHERE order_no = ?', ['ML-100003']) === null) {
    $locationId = (int) Db::value('SELECT id FROM locations WHERE user_id = ? LIMIT 1', [$albertId]);
    $vendorId = $vendorIds['Kilimani Pure Water'];
    $orderId = Db::insert('orders', [
        'order_no'          => 'ML-100003',
        'customer_id'       => $albertId,
        'vendor_id'         => $vendorId,
        'location_id'       => $locationId,
        'status_id'         => $pendingStatus,
        'quantity'          => 4,
        'unit_price'        => '60.00',
        'delivery_fee'      => '50.00',
        'total'             => '290.00',
        'commission_amount' => '24.00',
        'delivery_lat'      => -1.29250,
        'delivery_lng'      => 36.78750,
        'delivery_alias'    => "Albert's House",
        'note'              => '',
        'requested_at'      => gmdate('Y-m-d H:i:s', $now),
        'expires_at'        => gmdate('Y-m-d H:i:s', $now + 300),
    ]);
    Db::insert('order_status_history', ['order_id' => $orderId, 'status_id' => $pendingStatus, 'changed_by' => $albertId, 'note' => 'Order placed']);
}

if ($adminNotice !== null) {
    echo $adminNotice . "\n";
}
echo "Seed complete. Demo accounts use the password from SEED_DEMO_PASSWORD.\n";
