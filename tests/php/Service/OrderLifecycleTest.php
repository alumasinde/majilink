<?php
declare(strict_types=1);

namespace MajiLink\Tests\Service;

use MajiLink\Services\OrderService;
use MajiLink\Services\WalletService;
use MajiLink\Support\Db;
use MajiLink\Support\Money;
use MajiLink\Tests\Support\MajiTestCase;

/**
 * The order state machine: one map of legal moves, a mandatory reason for vendor
 * cancellations, automatic expiry, and privacy that lasts until acceptance.
 */
final class OrderLifecycleTest extends MajiTestCase
{
    private OrderService $orders;
    private array $customer;
    private array $vendor;
    private array $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderService();
        $this->customer = $this->createUser('customer');
        $this->vendor = $this->createVendor();
        $this->location = $this->createLocation(
            (int) $this->customer['id'],
            (float) $this->vendor['base_lat'],
            (float) $this->vendor['base_lng'],
            "Albert's House"
        );
    }

    private function place(int $quantity = 2, string $method = 'Cash', ?array $location = null): array
    {
        return $this->orders->create((int) $this->customer['id'], [
            'vendor_id'      => (int) $this->vendor['id'],
            'location_id'    => (int) ($location['id'] ?? $this->location['id']),
            'quantity'       => $quantity,
            'payment_method' => $method,
        ]);
    }

    private function statusOf(int $orderId): string
    {
        return (string) Db::value(
            'SELECT s.name FROM orders o JOIN order_statuses s ON s.id = o.status_id WHERE o.id = ?',
            [$orderId]
        );
    }

    private function historyCount(int $orderId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?', [$orderId]);
    }

    public function testNewOrderIsPendingAndPricedFromTheVendorAndSettings(): void
    {
        $order = $this->place(2);

        $this->assertSame('Pending', $order['status']['name']);
        $this->assertFalse($order['status']['is_final']);
        $this->assertSame('60.00', $order['unit_price']);
        $this->assertSame('50.00', $order['delivery_fee']);
        $this->assertSame('170.00', $order['total']);
        $this->assertSame('Cash', $order['payment']['method']);
        $this->assertSame('Unpaid', $order['payment']['status']);
        $this->assertMatchesRegularExpression('/^ML-\d+$/', $order['order_no']);
        $this->assertSame(1, $this->historyCount((int) $order['id']));
        $this->assertNotNull($order['expires_at']);
        $this->assertGreaterThan(0, $order['expires_in_seconds']);
    }

    public function testThePriceIsCopiedOntoTheOrderSoLaterChangesDoNotRewriteHistory(): void
    {
        $order = $this->place(2);

        Db::update('vendors', ['price_per_20l' => '99.00'], 'id = ?', [$this->vendor['id']]);

        $stored = Db::one('SELECT unit_price, total FROM orders WHERE id = ?', [$order['id']]);
        $this->assertSame('60.00', $stored['unit_price']);
        $this->assertSame('170.00', $stored['total']);
    }

    public function testAcceptedOrderExposesTheDeliveryPinThatWasHiddenBefore(): void
    {
        $order = $this->place(2);

        $before = $this->orders->forVendor((int) $this->vendor['id'], (int) $order['id']);
        $this->assertNull($before['delivery']['alias']);
        $this->assertNull($before['delivery']['lat']);
        $this->assertNull($before['customer']['last_name']);
        $this->assertNull($before['customer']['phone']);
        $this->assertTrue($before['actions']['accept']);
        $this->assertFalse($before['actions']['navigate']);

        $accepted = $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);

        $this->assertSame('Accepted', $accepted['status']['name']);
        $this->assertSame("Albert's House", $accepted['delivery']['alias']);
        $this->assertNotNull($accepted['delivery']['lat']);
        $this->assertSame($this->customer['last_name'], $accepted['customer']['last_name']);
        $this->assertTrue($accepted['actions']['navigate']);
        $this->assertTrue($accepted['actions']['call_customer']);
        $this->assertNotNull($accepted['accepted_at']);
    }

    public function testHappyPathRunsPendingAcceptedOnTheWayDelivered(): void
    {
        $order = $this->place(2);

        $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);
        $this->orders->markOnTheWay((int) $this->vendor['id'], (int) $order['id']);
        $delivered = $this->orders->markDelivered((int) $this->vendor['id'], (int) $order['id']);

        $this->assertSame('Delivered', $delivered['status']['name']);
        $this->assertTrue($delivered['status']['is_final']);
        $this->assertNotNull($delivered['delivered_at']);
        $this->assertSame(4, $this->historyCount((int) $order['id']));

        // Rating is a customer action: the vendor view never offers it.
        $customerView = $this->orders->forCustomer((int) $this->customer['id'], (int) $order['id']);
        $this->assertTrue($customerView['actions']['rate']);
        $this->assertArrayNotHasKey('rate', $delivered['actions']);
    }

    public function testDeliveryCannotBeMarkedFromPending(): void
    {
        $order = $this->place(2);

        $this->assertHttpStatus(
            409,
            fn () => $this->orders->markDelivered((int) $this->vendor['id'], (int) $order['id']),
            'conflict'
        );
        $this->assertSame('Pending', $this->statusOf((int) $order['id']));
    }

    public function testAcceptingTwiceIsRejected(): void
    {
        $order = $this->place(2);
        $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);

        $this->assertHttpStatus(409, fn () => $this->orders->accept((int) $this->vendor['id'], (int) $order['id']));
    }

    public function testDeliveredOrderCannotMoveAgain(): void
    {
        $order = $this->place(2);
        $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);
        $this->orders->markDelivered((int) $this->vendor['id'], (int) $order['id']);

        $this->assertHttpStatus(409, fn () => $this->orders->markOnTheWay((int) $this->vendor['id'], (int) $order['id']));
        $this->assertHttpStatus(409, fn () => $this->orders->accept((int) $this->vendor['id'], (int) $order['id']));
    }

    public function testAnotherVendorCannotTouchTheOrder(): void
    {
        $order = $this->place(2);
        $other = $this->createVendor();

        $this->assertHttpStatus(404, fn () => $this->orders->accept((int) $other['id'], (int) $order['id']), 'not_found');
    }

    public function testAnotherCustomerCannotReadTheOrder(): void
    {
        $order = $this->place(2);
        $stranger = $this->createUser('customer');

        $this->assertHttpStatus(404, fn () => $this->orders->forCustomer((int) $stranger['id'], (int) $order['id']));
    }

    public function testCustomerCanCancelWhilePendingAndAccepted(): void
    {
        $pending = $this->place(1);
        $cancelled = $this->orders->cancelByCustomer((int) $this->customer['id'], (int) $pending['id'], 'Changed my mind');
        $this->assertSame('Cancelled', $cancelled['status']['name']);

        $accepted = $this->place(1);
        $this->orders->accept((int) $this->vendor['id'], (int) $accepted['id']);
        $this->assertSame(
            'Cancelled',
            $this->orders->cancelByCustomer((int) $this->customer['id'], (int) $accepted['id'])['status']['name']
        );
    }

    public function testCustomerCannotCancelOnceTheVendorIsOnTheWay(): void
    {
        $order = $this->place(1);
        $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);
        $this->orders->markOnTheWay((int) $this->vendor['id'], (int) $order['id']);

        $this->assertHttpStatus(409, fn () => $this->orders->cancelByCustomer((int) $this->customer['id'], (int) $order['id']));
    }

    public function testVendorCancellationRequiresAReason(): void
    {
        $order = $this->place(1);
        $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);

        $error = $this->assertHttpStatus(
            422,
            fn () => $this->orders->cancelByVendor((int) $this->vendor['id'], (int) $order['id'], '   ')
        );
        $this->assertArrayHasKey('reason', $error->fields);

        $cancelled = $this->orders->cancelByVendor((int) $this->vendor['id'], (int) $order['id'], 'Out of stock');
        $this->assertSame('Cancelled', $cancelled['status']['name']);

        $stored = Db::one('SELECT cancel_reason, cancelled_by FROM orders WHERE id = ?', [$order['id']]);
        $this->assertSame('Out of stock', $stored['cancel_reason']);
        $this->assertSame((int) $this->vendor['user_id'], (int) $stored['cancelled_by']);
    }

    public function testVendorCannotCancelWithTheGenericDeclinePathBeforeAccepting(): void
    {
        $order = $this->place(1);

        $this->assertHttpStatus(
            409,
            fn () => $this->orders->cancelByVendor((int) $this->vendor['id'], (int) $order['id'], 'Too far')
        );

        $declined = $this->orders->decline((int) $this->vendor['id'], (int) $order['id'], 'Too far');
        $this->assertSame('Declined', $declined['status']['name']);
    }

    public function testUnansweredOrderExpiresAndCanNoLongerBeAccepted(): void
    {
        $order = $this->place(1);
        Db::update('orders', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$order['id']]);

        $this->assertGreaterThanOrEqual(1, $this->orders->expireStale());
        $this->assertSame('Expired', $this->statusOf((int) $order['id']));
        $this->assertHttpStatus(409, fn () => $this->orders->accept((int) $this->vendor['id'], (int) $order['id']));
    }

    public function testAcceptingAPastExpiryExpiresTheOrderOnTheSpot(): void
    {
        $order = $this->place(1);
        Db::update('orders', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], 'id = ?', [$order['id']]);

        $this->assertHttpStatus(409, fn () => $this->orders->accept((int) $this->vendor['id'], (int) $order['id']));

        // The failed accept also closes the order, so the customer stops waiting
        // on a vendor that can no longer take it.
        $this->assertSame('Expired', $this->statusOf((int) $order['id']));
    }

    public function testOrderToAPinOutsideTheVendorsRadiusIsRefused(): void
    {
        // ~4 km north of the vendor, whose delivery radius is 3 km.
        $far = $this->createLocation(
            (int) $this->customer['id'],
            (float) $this->vendor['base_lat'] + 0.03597,
            (float) $this->vendor['base_lng'],
            'Far house'
        );

        $error = $this->assertHttpStatus(422, fn () => $this->place(1, 'Cash', $far));
        $this->assertArrayHasKey('vendor_id', $error->fields);
    }

    public function testOfflineVendorCannotReceiveOrders(): void
    {
        Db::update('vendors', ['is_online' => 0], 'id = ?', [$this->vendor['id']]);

        $this->assertHttpStatus(409, fn () => $this->place(1));
    }

    public function testVendorWithASilentHeartbeatIsTreatedAsOffline(): void
    {
        Db::update(
            'vendors',
            ['last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 60 * 60)],
            'id = ?',
            [$this->vendor['id']]
        );

        $this->assertHttpStatus(409, fn () => $this->place(1));
    }

    public function testCashDeliveryCreditsGrossThenDebitsCommission(): void
    {
        $order = $this->place(2);
        $this->orders->accept((int) $this->vendor['id'], (int) $order['id']);
        $this->orders->markDelivered((int) $this->vendor['id'], (int) $order['id']);

        $wallet = new WalletService();
        $this->assertSame(15800, $wallet->balanceCents((int) $this->vendor['id'])); // 170.00 - 12.00

        $types = array_column($wallet->transactions((int) $this->vendor['id']), 'type');
        $this->assertContains('Order earning', $types);
        $this->assertContains('Commission', $types);
    }

    public function testAdminOverrideNeedsAReasonAndIsAudited(): void
    {
        $order = $this->place(1);
        $admin = $this->createUser('admin');

        $error = $this->assertHttpStatus(
            422,
            fn () => $this->orders->override((int) $order['id'], (int) $admin['id'], 'Delivered', '   ')
        );
        $this->assertArrayHasKey('reason', $error->fields);

        $this->orders->override((int) $order['id'], (int) $admin['id'], 'Delivered', 'Delivered by hand', '127.0.0.1');

        $this->assertSame('Delivered', $this->statusOf((int) $order['id']));
        $this->assertSame(1, (int) Db::value(
            "SELECT COUNT(*) FROM audit_logs WHERE entity = 'orders' AND entity_id = ?",
            [$order['id']]
        ));
    }

    public function testOrderNoIsUniqueAndSequentialInShape(): void
    {
        $first = $this->place(1);
        $second = $this->place(1);

        $this->assertNotSame($first['order_no'], $second['order_no']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM orders WHERE order_no = ?', [$first['order_no']]));
    }

    public function testTotalIsTheSumStoredOnTheOrder(): void
    {
        $order = $this->place(3);

        $row = Db::one('SELECT quantity, unit_price, delivery_fee, total, commission_amount FROM orders WHERE id = ?', [$order['id']]);
        $expectedTotal = Money::cents($row['unit_price']) * (int) $row['quantity'] + Money::cents($row['delivery_fee']);

        $this->assertSame(Money::decimal($expectedTotal), $row['total']);
        $this->assertSame(Money::decimal(Money::percentOf(Money::cents($row['unit_price']) * 3, 10.0)), $row['commission_amount']);
    }
}
