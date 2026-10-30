<?php
declare(strict_types=1);

/**
 * Run every minute from cron:
 *   * * * * * php /path/to/majilink/cron.php >> /path/to/storage/logs/cron.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use MajiLink\Services\OrderService;
use MajiLink\Services\RecurringService;
use MajiLink\Services\SettingsService;
use MajiLink\Support\Db;

$settings = new SettingsService();

// 1. Pending orders past their acceptance window become Expired.
$expired = (new OrderService())->expireStale();

// 2. Due repeat deliveries become fresh Pending orders.
$recurring = (new RecurringService())->runDue();

// 3. Vendors that stopped sending heartbeats go offline.
$offline = Db::run(
    "UPDATE vendors v
     JOIN vendor_application_statuses s ON s.id = v.application_status_id
     SET v.is_online = 0
     WHERE s.name = 'Approved' AND v.is_online = 1
       AND (v.last_heartbeat_at IS NULL OR v.last_heartbeat_at < ?)",
    [$settings->heartbeatCutoff()]
)->rowCount();

echo json_encode([
    'expired_orders'   => $expired,
    'recurring_orders' => $recurring,
    'vendors_offline'  => $offline,
    'ran_at'           => gmdate('c'),
], JSON_PRETTY_PRINT) . PHP_EOL;
