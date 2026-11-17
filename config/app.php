<?php
declare(strict_types=1);

use MajiLink\Support\Env;

$env = (string) Env::get('APP_ENV', 'production');
$localUrl = 'http://localhost:8099';
$productionUrl = 'https://majilink.co.ke';

$url = trim((string) Env::get('APP_URL', ''));
if ($url === '') {
    $url = $env === 'production' ? $productionUrl : $localUrl;
}
// A production deployment must never advertise a loopback address.
$isLoopback = preg_match(
    '#^(?:https?://)?(?:localhost|127(?:\.\d{1,3}){3}|0\.0\.0\.0|\[?::1\]?)(?::\d+)?(?:/|$)#i',
    $url
) === 1;
if ($env === 'production' && $isLoopback) {
    $url = $productionUrl;
}

return [
    'env'          => $env,
    'debug'        => Env::bool('APP_DEBUG', false),
    'name'         => 'MajiLink',
    'url'          => rtrim($url, '/'),
    'key'          => Env::get('APP_KEY', ''),
    'access_ttl'   => 900,          // 15 minutes
    'refresh_ttl'  => 2592000,      // 30 days
    'timezone'     => 'UTC',
    'allowed_origins' => array_map('trim', explode(',', Env::get('ALLOWED_ORIGINS', '*'))),
    'otp' => [
        'length'         => 6,
        'ttl'            => 600,
        'resend_seconds' => 60,
        'max_attempts'   => 5,
    ],
    'upload_dir'    => BASE_PATH . '/storage/uploads',
    'log_dir'       => BASE_PATH . '/storage/logs',
    'cache_dir'     => BASE_PATH . '/storage/cache',
    'mail_from'     => Env::get('MAIL_FROM', 'no-reply@majilink.co.ke'),
    'mail_from_name'=> Env::get('MAIL_FROM_NAME', 'MajiLink'),
    'otp_dev_expose'=> Env::bool('OTP_DEV_EXPOSE', false),
    'routing_url'   => Env::get('ROUTING_URL', 'https://router.project-osrm.org'),
    'mpesa' => [
        'env'             => Env::get('MPESA_ENV', 'sandbox'),
        'consumer_key'    => Env::get('MPESA_CONSUMER_KEY', ''),
        'consumer_secret' => Env::get('MPESA_CONSUMER_SECRET', ''),
        'shortcode'       => Env::get('MPESA_SHORTCODE', ''),
        'passkey'         => Env::get('MPESA_PASSKEY', ''),
        'callback_url'    => Env::get('MPESA_CALLBACK_URL', ''),
        'simulate'        => Env::bool('MPESA_SIMULATE', false),
    ],
    'fcm' => [
        'project_id' => Env::get('FCM_PROJECT_ID', ''),
        'service_account' => Env::get('FCM_SERVICE_ACCOUNT_JSON', ''),
    ],
];
