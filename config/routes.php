<?php
declare(strict_types=1);

use MajiLink\Controllers\Api\AdminApiController;
use MajiLink\Controllers\Api\AuthController;
use MajiLink\Controllers\Api\CallbackController;
use MajiLink\Controllers\Api\ConfigController;
use MajiLink\Controllers\Api\CustomerController;
use MajiLink\Controllers\Api\VendorController;
use MajiLink\Http\Router;

return static function (Router $router): void {
    $auth = new AuthController();
    $config = new ConfigController();
    $customer = new CustomerController();
    $vendor = new VendorController();
    $admin = new AdminApiController();
    $callbacks = new CallbackController();

    // --- Public / auth -----------------------------------------------------
    $router->get('/api/v1/health', static fn () => \MajiLink\Http\Response::ok(['status' => 'ok', 'time' => gmdate('c')]));
    // Always reachable, even under maintenance, so a gated client can read why it is gated.
    $router->get('/api/v1/config', [$config, 'show']);
    $router->post('/api/v1/auth/otp/request', [$auth, 'requestOtp']);
    $router->post('/api/v1/auth/otp/verify', [$auth, 'verifyOtp']);
    $router->get('/api/v1/auth/channels', [$auth, 'channels']);
    $router->post('/api/v1/auth/register', [$auth, 'register']);
    $router->post('/api/v1/auth/login', [$auth, 'login']);
    $router->post('/api/v1/auth/login/pin', [$auth, 'loginPin']);
    $router->post('/api/v1/auth/refresh', [$auth, 'refresh']);
    $router->post('/api/v1/auth/password/forgot', [$auth, 'forgotPassword']);
    $router->post('/api/v1/auth/password/reset', [$auth, 'resetPassword']);

    // --- Authenticated account ---------------------------------------------
    $router->get('/api/v1/auth/me', [$auth, 'me'], ['auth' => true]);
    $router->post('/api/v1/auth/pin', [$auth, 'setPin'], ['auth' => true]);
    $router->post('/api/v1/auth/biometric', [$auth, 'biometric'], ['auth' => true]);
    $router->post('/api/v1/auth/push-token', [$auth, 'pushToken'], ['auth' => true]);
    $router->post('/api/v1/auth/logout', [$auth, 'logout'], ['auth' => true]);
    $router->get('/api/v1/auth/devices', [$auth, 'devices'], ['auth' => true]);
    $router->delete('/api/v1/auth/devices/{id}', [$auth, 'revokeDevice'], ['auth' => true]);

    // --- Customer ----------------------------------------------------------
    $router->get('/api/v1/locations', [$customer, 'listLocations'], ['auth' => true, 'permission' => 'locations.manage']);
    $router->post('/api/v1/locations', [$customer, 'createLocation'], ['auth' => true, 'permission' => 'locations.manage']);
    $router->put('/api/v1/locations/{id}', [$customer, 'updateLocation'], ['auth' => true, 'permission' => 'locations.manage']);
    $router->delete('/api/v1/locations/{id}', [$customer, 'deleteLocation'], ['auth' => true, 'permission' => 'locations.manage']);

    $router->get('/api/v1/vendors/nearby', [$customer, 'nearbyVendors'], ['auth' => true, 'permission' => 'orders.create']);
    $router->get('/api/v1/vendors/{id}', [$customer, 'showVendor'], ['auth' => true, 'permission' => 'orders.create']);

    $router->post('/api/v1/orders/quote', [$customer, 'quote'], ['auth' => true, 'permission' => 'orders.create']);
    $router->post('/api/v1/orders', [$customer, 'createOrder'], ['auth' => true, 'permission' => 'orders.create']);
    $router->get('/api/v1/orders', [$customer, 'listOrders'], ['auth' => true, 'permission' => 'orders.view_own']);
    $router->get('/api/v1/orders/{id}', [$customer, 'showOrder'], ['auth' => true, 'permission' => 'orders.view_own']);
    $router->post('/api/v1/orders/{id}/cancel', [$customer, 'cancelOrder'], ['auth' => true, 'permission' => 'orders.view_own']);
    $router->post('/api/v1/orders/{id}/rate', [$customer, 'rateOrder'], ['auth' => true, 'permission' => 'ratings.create']);
    $router->post('/api/v1/orders/{id}/pay/mpesa', [$customer, 'payWithMpesa'], ['auth' => true, 'permission' => 'orders.view_own']);

    $router->get('/api/v1/recurring-orders', [$customer, 'listRecurring'], ['auth' => true, 'permission' => 'orders.view_own']);
    $router->post('/api/v1/recurring-orders', [$customer, 'createRecurring'], ['auth' => true, 'permission' => 'orders.create']);
    $router->put('/api/v1/recurring-orders/{id}', [$customer, 'updateRecurring'], ['auth' => true, 'permission' => 'orders.create']);
    $router->delete('/api/v1/recurring-orders/{id}', [$customer, 'deleteRecurring'], ['auth' => true, 'permission' => 'orders.create']);

    $router->get('/api/v1/notifications', [$customer, 'notifications'], ['auth' => true]);
    $router->post('/api/v1/notifications/{id}/read', [$customer, 'readNotification'], ['auth' => true]);

    // --- Vendor ------------------------------------------------------------
    $router->post('/api/v1/vendor/apply', [$vendor, 'apply'], ['auth' => true]);
    $router->get('/api/v1/vendor/application', [$vendor, 'application'], ['auth' => true]);
    $router->post('/api/v1/vendor/documents', [$vendor, 'uploadDocument'], ['auth' => true]);
    $router->get('/api/v1/vendor/profile', [$vendor, 'profile'], ['auth' => true]);
    $router->put('/api/v1/vendor/profile', [$vendor, 'updateProfile'], ['auth' => true]);
    $router->post('/api/v1/vendor/online', [$vendor, 'setOnline'], ['auth' => true, 'permission' => 'vendors.toggle_online']);
    $router->post('/api/v1/vendor/heartbeat', [$vendor, 'heartbeat'], ['auth' => true, 'permission' => 'vendors.toggle_online']);
    $router->get('/api/v1/vendor/orders', [$vendor, 'orders'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->get('/api/v1/vendor/orders/{id}', [$vendor, 'showOrder'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->post('/api/v1/vendor/orders/{id}/accept', [$vendor, 'accept'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->post('/api/v1/vendor/orders/{id}/decline', [$vendor, 'decline'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->post('/api/v1/vendor/orders/{id}/on-the-way', [$vendor, 'onTheWay'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->post('/api/v1/vendor/orders/{id}/delivered', [$vendor, 'delivered'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->post('/api/v1/vendor/orders/{id}/cancel', [$vendor, 'cancelOrder'], ['auth' => true, 'permission' => 'orders.accept']);
    $router->get('/api/v1/vendor/wallet', [$vendor, 'wallet'], ['auth' => true, 'permission' => 'wallet.view']);
    $router->get('/api/v1/vendor/transactions', [$vendor, 'transactions'], ['auth' => true, 'permission' => 'wallet.view']);
    $router->get('/api/v1/vendor/payouts', [$vendor, 'payouts'], ['auth' => true, 'permission' => 'payouts.request']);
    $router->post('/api/v1/vendor/payouts', [$vendor, 'requestPayout'], ['auth' => true, 'permission' => 'payouts.request']);
    $router->get('/api/v1/vendor/ratings', [$vendor, 'ratings'], ['auth' => true, 'permission' => 'orders.accept']);

    // --- Admin tooling -----------------------------------------------------
    $router->get('/api/v1/admin/vendors/applications', [$admin, 'applications'], ['auth' => true, 'permission' => 'vendors.review']);
    $router->post('/api/v1/admin/vendors/{id}/review', [$admin, 'review'], ['auth' => true, 'permission' => 'vendors.review']);
    $router->get('/api/v1/admin/vendors', [$admin, 'vendors'], ['auth' => true, 'permission' => 'vendors.view']);
    $router->post('/api/v1/admin/vendors/{id}/suspend', [$admin, 'suspend'], ['auth' => true, 'permission' => 'vendors.suspend']);
    $router->get('/api/v1/admin/orders', [$admin, 'orders'], ['auth' => true, 'permission' => 'orders.view_all']);
    $router->get('/api/v1/admin/orders/{id}', [$admin, 'order'], ['auth' => true, 'permission' => 'orders.view_all']);
    $router->post('/api/v1/admin/orders/{id}/status', [$admin, 'overrideOrder'], ['auth' => true, 'permission' => 'orders.override_status']);
    $router->get('/api/v1/admin/payments', [$admin, 'payments'], ['auth' => true, 'permission' => 'payments.view']);
    $router->get('/api/v1/admin/payouts', [$admin, 'payouts'], ['auth' => true, 'permission' => 'payouts.process']);
    $router->post('/api/v1/admin/payouts/{id}/process', [$admin, 'processPayout'], ['auth' => true, 'permission' => 'payouts.process']);
    $router->get('/api/v1/admin/reports', [$admin, 'reports'], ['auth' => true, 'permission' => 'reports.view']);
    $router->post('/api/v1/admin/broadcast', [$admin, 'broadcast'], ['auth' => true, 'permission' => 'notifications.broadcast']);

    // --- Provider callbacks ------------------------------------------------
    $router->post('/api/v1/callbacks/mpesa', [$callbacks, 'mpesa']);
};
