<?php
declare(strict_types=1);

/**
 * End-to-end smoke test. Run against a live dev server:
 *   php -S 127.0.0.1:8099 -t public public/router.php
 *   php tests/api_smoke.php
 */

$base = getenv('MAJILINK_BASE') ?: 'http://127.0.0.1:8099/api/v1';
$pass = 0;
$fail = 0;
$failures = [];

function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $versionCode = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($versionCode !== null) {
        $headers[] = 'X-App-Version-Code: ' . $versionCode;
        $headers[] = 'X-App-Version-Name: smoke';
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}

function check(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($condition) {
        $pass++;
        echo "  ok   {$label}\n";
        return;
    }
    $fail++;
    $failures[] = $label . ($detail !== '' ? ' :: ' . $detail : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

function token(array $response): ?string
{
    return $response['json']['data']['access_token'] ?? null;
}

$stamp = time();
$email = 'smoke' . $stamp . '@example.com';
$phone = '0712' . substr((string) ($stamp % 1000000), 0, 6);
$password = 'Maji12345!';
$pin = '481920';

echo "\n== 1. OTP registration ==\n";
$otp = call('POST', '/auth/otp/request', ['destination' => $email, 'purpose' => 'register']);
check('OTP request accepted', $otp['status'] === 200, $otp['raw']);
$code = $otp['json']['data']['dev_code'] ?? null;
check('Dev code exposed locally', is_string($code));

$verify = call('POST', '/auth/otp/verify', ['destination' => $email, 'purpose' => 'register', 'code' => $code]);
check('OTP verifies', $verify['status'] === 200, $verify['raw']);
$verificationToken = $verify['json']['data']['verification_token'] ?? null;

$badVerify = call('POST', '/auth/otp/verify', ['destination' => $email, 'purpose' => 'register', 'code' => '000000']);
check('Wrong OTP rejected', in_array($badVerify['status'], [400, 422], true), $badVerify['raw']);

$device = ['device_uid' => 'smoke-device-' . $stamp, 'device_name' => 'Smoke Pixel', 'pin' => $pin];

echo "\n== 2. Register + login ==\n";
$register = call('POST', '/auth/register', [
    'verification_token' => $verificationToken,
    'first_name'         => 'Smoke',
    'last_name'          => 'Tester',
    'phone'              => $phone,
    'email'              => $email,
    'password'           => $password,
    'device'             => $device,
]);
check('Registration succeeds', $register['status'] === 201, $register['raw']);
$customerToken = token($register);
check('Access token issued', is_string($customerToken));
check('Refresh token issued', isset($register['json']['data']['refresh_token']));
check('Customer mode enabled', in_array('customer', $register['json']['data']['user']['modes'] ?? [], true));

$login = call('POST', '/auth/login', ['identifier' => $email, 'password' => $password, 'device' => $device]);
check('Password login succeeds', $login['status'] === 200, $login['raw']);
$customerToken = token($login);

$pinLogin = call('POST', '/auth/login/pin', ['identifier' => $email, 'device_uid' => $device['device_uid'], 'pin' => $pin]);
check('PIN login succeeds', $pinLogin['status'] === 200, $pinLogin['raw']);

$wrongPin = call('POST', '/auth/login/pin', ['identifier' => $email, 'device_uid' => $device['device_uid'], 'pin' => '123456']);
check('Wrong PIN rejected', $wrongPin['status'] === 422, $wrongPin['raw']);

// The PIN login above rotated the device's refresh token, so that is the live one.
$refresh = call('POST', '/auth/refresh', [
    'refresh_token' => $pinLogin['json']['data']['refresh_token'],
    'device_uid'    => $device['device_uid'],
]);
check('Refresh rotates the session', $refresh['status'] === 200, $refresh['raw']);

$badRefresh = call('POST', '/auth/refresh', [
    'refresh_token' => $login['json']['data']['refresh_token'],
    'device_uid'    => $device['device_uid'],
]);
check('Rotated refresh token is rejected', $badRefresh['status'] === 401, $badRefresh['raw']);

echo "\n== 3. Vendor presence and discovery ==\n";
$vendorLogin = call('POST', '/auth/login', [
    'identifier' => 'peter@kilimaniwater.co.ke', 'password' => $password,
    'device' => ['device_uid' => 'smoke-vendor-' . $stamp, 'device_name' => 'Vendor Phone'],
]);
check('Vendor password login succeeds', $vendorLogin['status'] === 200, $vendorLogin['raw']);
$vendorToken = token($vendorLogin);
check('Vendor mode available', in_array('vendor', $vendorLogin['json']['data']['user']['modes'] ?? [], true));
$online = call('POST', '/vendor/online', ['is_online' => true], $vendorToken);
check('Vendor goes online', $online['status'] === 200, $online['raw']);
$beat = call('POST', '/vendor/heartbeat', null, $vendorToken);
check('Heartbeat accepted', $beat['status'] === 200, $beat['raw']);

$maryLogin = call('POST', '/auth/login', [
    'identifier' => 'mary@kileleshwa.co.ke', 'password' => $password,
    'device' => ['device_uid' => 'smoke-vendor2-' . $stamp, 'device_name' => 'Vendor Phone 2'],
]);
$maryToken = token($maryLogin);
call('POST', '/vendor/online', ['is_online' => true], $maryToken);
call('POST', '/vendor/heartbeat', null, $maryToken);
$loc = call('POST', '/locations', [
    'alias' => 'Smoke House', 'lat' => -1.2925, 'lng' => 36.7875, 'landmark_note' => 'Blue gate', 'is_default' => true,
], $customerToken);
check('Location created', $loc['status'] === 201, $loc['raw']);
$locationId = $loc['json']['data']['id'] ?? 0;
check('First pin becomes default', (int) ($loc['json']['data']['is_default'] ?? 0) === 1);

$nearby = call('GET', '/vendors/nearby?location_id=' . $locationId, null, $customerToken);
check('Nearby vendors returned', $nearby['status'] === 200 && count($nearby['json']['data']['vendors'] ?? []) > 0, $nearby['raw']);
$vendors = $nearby['json']['data']['vendors'] ?? [];
check('Vendors sorted by distance', count($vendors) > 1 ? $vendors[0]['distance_km'] <= $vendors[1]['distance_km'] : true);
$kilimani = null;
foreach ($vendors as $v) {
    if ($v['business_name'] === 'Kilimani Pure Water') {
        $kilimani = $v;
    }
}
check('Seeded Kilimani vendor visible (online + heartbeat)', $kilimani !== null, json_encode(array_column($vendors, 'business_name')));
check('Road ETA filled for top results', isset($vendors[0]['eta_minutes']) && $vendors[0]['eta_minutes'] !== null);

echo "\n== 4. Cash order lifecycle ==\n";
$order = call('POST', '/orders', [
    'vendor_id'      => $kilimani['id'] ?? 0,
    'location_id'    => $locationId,
    'quantity'       => 2,
    'note'           => 'Call at the gate',
    'payment_method' => 'Cash',
], $customerToken);
check('Order created', $order['status'] === 201, $order['raw']);
$orderId = $order['json']['data']['id'] ?? 0;
check('Unit price copied from vendor', ($order['json']['data']['unit_price'] ?? '') === '60.00', $order['raw']);
check('Commission hidden from the customer view', !isset($order['json']['data']['commission_amount']));
check('Total = items + delivery fee', isset($order['json']['data']['total']));

$dupeQty = call('POST', '/orders', [
    'vendor_id' => $kilimani['id'] ?? 0, 'location_id' => $locationId, 'quantity' => 999, 'payment_method' => 'Cash',
], $customerToken);
check('Quantity limits enforced', $dupeQty['status'] === 422, $dupeQty['raw']);

$vendorOrders = call('GET', '/vendor/orders?filter=active', null, $vendorToken);
check('Vendor sees the pending order', $vendorOrders['status'] === 200 && count($vendorOrders['json']['data']['orders'] ?? []) > 0, $vendorOrders['raw']);
$firstVendorOrder = $vendorOrders['json']['data']['orders'][0] ?? [];
check('Pre-accept privacy hides the pin and phone', ($firstVendorOrder['delivery']['lat'] ?? null) === null && ($firstVendorOrder['customer']['phone'] ?? null) === null, json_encode($firstVendorOrder['customer'] ?? []));

$accept = call('POST', '/vendor/orders/' . $orderId . '/accept', null, $vendorToken);
check('Vendor accepts', $accept['status'] === 200, $accept['raw']);
check('Accepted order reveals the exact pin', isset($accept['json']['data']['delivery']['lat']));

$doubleAccept = call('POST', '/vendor/orders/' . $orderId . '/accept', null, $vendorToken);
check('Illegal transition rejected', $doubleAccept['status'] === 409, $doubleAccept['raw']);

$onTheWay = call('POST', '/vendor/orders/' . $orderId . '/on-the-way', null, $vendorToken);
check('Vendor marks on the way', $onTheWay['status'] === 200, $onTheWay['raw']);

$delivered = call('POST', '/vendor/orders/' . $orderId . '/delivered', null, $vendorToken);
check('Vendor marks delivered', $delivered['status'] === 200, $delivered['raw']);
check('Delivered is final', ($delivered['json']['data']['status']['is_final'] ?? false) === true);

$wallet = call('GET', '/vendor/wallet', null, $vendorToken);
check('Wallet credited for cash delivery', $wallet['status'] === 200 && (float) ($wallet['json']['data']['balance'] ?? 0) > 0, $wallet['raw']);

$rate = call('POST', '/orders/' . $orderId . '/rate', ['stars' => 5, 'comment' => 'Great service'], $customerToken);
check('Customer rates delivered order', $rate['status'] === 201, $rate['raw']);
$rateAgain = call('POST', '/orders/' . $orderId . '/rate', ['stars' => 4], $customerToken);
check('One rating per order', $rateAgain['status'] === 409, $rateAgain['raw']);

$isolation = call('GET', '/orders/' . $orderId, null, $vendorToken);
check('Vendor token cannot read another customer\'s order', $isolation['status'] === 404, $isolation['raw']);

echo "\n== 5. M-Pesa order + idempotent callback ==\n";
$mpesaOrder = call('POST', '/orders', [
    'vendor_id' => $kilimani['id'] ?? 0, 'location_id' => $locationId, 'quantity' => 3, 'payment_method' => 'M-Pesa',
], $customerToken);
check('M-Pesa order created', $mpesaOrder['status'] === 201, $mpesaOrder['raw']);
$mpesaOrderId = $mpesaOrder['json']['data']['id'] ?? 0;

$stk = call('POST', '/orders/' . $mpesaOrderId . '/pay/mpesa', ['phone' => $phone], $customerToken);
check('STK push initiated', $stk['status'] === 200, $stk['raw']);
$checkoutId = $stk['json']['data']['checkout_request_id'] ?? '';

$callbackBody = [
    'Body' => [
        'stkCallback' => [
            'MerchantRequestID' => 'MR-' . $stamp,
            'CheckoutRequestID' => $checkoutId,
            'ResultCode'        => 0,
            'ResultDesc'        => 'The service request is processed successfully.',
            'CallbackMetadata'  => ['Item' => [
                ['Name' => 'Amount', 'Value' => 290],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'SMK' . substr((string) $stamp, -6)],
                ['Name' => 'PhoneNumber', 'Value' => $phone],
            ]],
        ],
    ],
];
$cb = call('POST', '/callbacks/mpesa', $callbackBody);
check('Callback accepted', $cb['status'] === 200 && ($cb['json']['data']['status'] ?? '') === 'Paid', $cb['raw']);
$cb2 = call('POST', '/callbacks/mpesa', $callbackBody);
check('Duplicate callback is idempotent', $cb2['status'] === 200 && ($cb2['json']['data']['duplicate'] ?? false) === true, $cb2['raw']);

$orderAfterPay = call('GET', '/orders/' . $mpesaOrderId, null, $customerToken);
check('Order shows payment as paid', ($orderAfterPay['json']['data']['payment']['status'] ?? '') === 'Paid', $orderAfterPay['raw']);

echo "\n== 6. Payouts ==\n";
$payoutRequest = call('POST', '/vendor/payouts', ['amount' => 10], $vendorToken);
check('Payout requested', $payoutRequest['status'] === 201, $payoutRequest['raw']);
$overRequest = call('POST', '/vendor/payouts', ['amount' => 999999], $vendorToken);
check('Payout above balance rejected', $overRequest['status'] === 422, $overRequest['raw']);

echo "\n== 7. Vendor application review ==\n";
$apply = call('POST', '/vendor/apply', [
    'business_name'      => 'Smoke Water Point ' . $stamp,
    'water_source_id'    => 1,
    'price_per_20l'      => 45,
    'delivery_radius_km' => 4,
    'base_lat'           => -1.2930,
    'base_lng'           => 36.7880,
    'base_note'          => 'Next to the shops',
    'opens_at'           => '06:00',
    'closes_at'          => '21:00',
    'payout_phone'       => $phone,
], $customerToken);
check('Customer can apply to become a vendor', $apply['status'] === 201, $apply['raw']);
$appliedVendorId = $apply['json']['data']['id'] ?? 0;
check('New application starts Pending', ($apply['json']['data']['status'] ?? '') === 'Pending');

$duplicateApply = call('POST', '/vendor/apply', [
    'business_name' => 'Second Shop', 'water_source_id' => 1, 'price_per_20l' => 40,
    'delivery_radius_km' => 3, 'base_lat' => -1.2930, 'base_lng' => 36.7880, 'payout_phone' => $phone,
], $customerToken);
check('Duplicate application blocked', $duplicateApply['status'] === 409, $duplicateApply['raw']);

$vendorEndpointsLocked = call('GET', '/vendor/orders', null, $customerToken);
check('Applicant cannot use vendor endpoints before approval', $vendorEndpointsLocked['status'] === 403, $vendorEndpointsLocked['raw']);

echo "\n== 8. Admin ==\n";
$adminLogin = call('POST', '/auth/login', [
    'identifier' => 'admin@majilink.co.ke', 'password' => $password,
    'device' => ['device_uid' => 'smoke-admin-' . $stamp, 'device_name' => 'Admin Laptop'],
]);
check('Admin login succeeds', $adminLogin['status'] === 200, $adminLogin['raw']);
$adminToken = token($adminLogin);

$denied = call('GET', '/admin/orders', null, $customerToken);
check('Customer blocked from admin endpoints', $denied['status'] === 403, $denied['raw']);

$applications = call('GET', '/admin/vendors/applications?status=Pending', null, $adminToken);
check('Admin lists pending applications', $applications['status'] === 200, $applications['raw']);
$pendingVendor = null;
foreach ($applications['json']['data']['applications'] ?? [] as $application) {
    if ((int) $application['id'] === (int) $appliedVendorId) {
        $pendingVendor = $application;
    }
}
check('New application appears in the review queue', $pendingVendor !== null, $applications['raw']);

if ($pendingVendor !== null) {
    $review = call('POST', '/admin/vendors/' . $pendingVendor['id'] . '/review', ['decision' => 'approve'], $adminToken);
    check('Admin approves application', $review['status'] === 200, $review['raw']);
    $reReview = call('POST', '/admin/vendors/' . $pendingVendor['id'] . '/review', ['decision' => 'approve'], $adminToken);
    check('Approved application cannot be re-reviewed', $reReview['status'] === 409, $reReview['raw']);

    $relogin = call('POST', '/auth/login', [
        'identifier' => $email, 'password' => $password, 'device' => $device,
    ]);
    $modes = $relogin['json']['data']['user']['modes'] ?? [];
    $customerToken = token($relogin) ?? $customerToken;
    check('Approval grants the vendor mode', in_array('vendor', $modes, true), json_encode($modes));
}

$reports = call('GET', '/admin/reports', null, $adminToken);
check('Admin reports load', $reports['status'] === 200, $reports['raw']);
check('Dashboard cards present', isset($reports['json']['data']['dashboard']['cards']['orders_today']));

$override = call('POST', '/admin/orders/' . $orderId . '/status', [
    'status' => 'Cancelled', 'reason' => 'Smoke test override after delivery',
], $adminToken);
check('Admin override works with a reason', $override['status'] === 200, $override['raw']);
$overrideNoReason = call('POST', '/admin/orders/' . $orderId . '/status', ['status' => 'Delivered', 'reason' => ''], $adminToken);
check('Override without reason rejected', $overrideNoReason['status'] === 422, $overrideNoReason['raw']);

$broadcast = call('POST', '/admin/broadcast', ['audience' => 'vendors', 'title' => 'Smoke', 'body' => 'Hello vendors'], $adminToken);
check('Broadcast sends', $broadcast['status'] === 200 && ($broadcast['json']['data']['sent_to'] ?? 0) > 0, $broadcast['raw']);

echo "\n== 9. Validation and abuse guards ==\n";
$weakPin = call('POST', '/auth/pin', ['pin' => '123456'], $customerToken);
check('Trivial PIN rejected', $weakPin['status'] === 422, $weakPin['raw']);

$badPhone = call('POST', '/auth/otp/request', ['destination' => 'not-a-phone', 'purpose' => 'register']);
check('Bad destination rejected', $badPhone['status'] === 422, $badPhone['raw']);

$unauth = call('GET', '/orders');
check('Missing token rejected', $unauth['status'] === 401, $unauth['raw']);

echo "\n== 10. Runtime config and app gate ==\n";
$config = call('GET', '/config');
check('Config is public', $config['status'] === 200, $config['raw']);
$app = $config['json']['data']['app'] ?? [];
check('Config carries the version floor', isset($app['min_supported_version_code']), $config['raw']);
check('Config carries the update prompt', isset($app['update_message'], $app['update_url'], $app['force_update']));
check('Config carries the maintenance switch', isset($config['json']['data']['maintenance']['enabled']));
check(
    'Config advertises an API base URL',
    str_ends_with((string) ($config['json']['data']['endpoint']['api_base_url'] ?? ''), '/api/v1/'),
    $config['raw']
);
check(
    'Config carries the public ordering limits',
    isset($config['json']['data']['limits']['min_quantity'], $config['json']['data']['limits']['max_quantity']),
    $config['raw']
);
check('Empty feature flags encode as an object', str_contains($config['raw'], '"flags":{}'), $config['raw']);

$gatedConfig = call('GET', '/config', null, null, '0');
check('Config stays reachable for an ancient APK', $gatedConfig['status'] === 200, $gatedConfig['raw']);

$versioned = call('GET', '/orders', null, $customerToken, '999999999');
check('Versioned client above the floor passes', $versioned['status'] === 200, $versioned['raw']);
$legacy = call('GET', '/orders', null, $customerToken);
check('Headerless legacy client is not gated', $legacy['status'] === 200, $legacy['raw']);

echo "\n----------------------------------------\n";
echo "PASS {$pass}  FAIL {$fail}\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}
echo "All smoke checks passed.\n";
