<?php
declare(strict_types=1);

namespace MajiLink\Tests\Service;

use MajiLink\Services\OrderService;
use MajiLink\Services\PayoutService;
use MajiLink\Services\WalletService;
use MajiLink\Support\Db;
use MajiLink\Support\Money;
use MajiLink\Tests\Support\MajiTestCase;

/**
 * Money out of the vendor wallet: cash earnings less commission, and payouts that
 * can never exceed what is actually available.
 */
final class WalletPayoutTest extends MajiTestCase
{
    private array $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = $this->createVendor();
    }

    private function deliverCashOrder(int $quantity = 2): int
    {
        $customer = $this->createUser('customer');
        $location = $this->createLocation(
            (int) $customer['id'],
            (float) $this->vendor['base_lat'],
            (float) $this->vendor['base_lng']
        );

        $orders = new OrderService();
        $order = $orders->create((int) $customer['id'], [
            'vendor_id'      => (int) $this->vendor['id'],
            'location_id'    => (int) $location['id'],
            'quantity'       => $quantity,
            'payment_method' => 'Cash',
        ]);
        $orders->accept((int) $this->vendor['id'], (int) $order['id']);
        $orders->markDelivered((int) $this->vendor['id'], (int) $order['id']);

        return (int) $order['id'];
    }

    public function testNewVendorStartsWithAnEmptyWallet(): void
    {
        $wallet = new WalletService();

        $this->assertSame(0, $wallet->balanceCents((int) $this->vendor['id']));
        $this->assertSame([], $wallet->transactions((int) $this->vendor['id']));
    }

    public function testCashDeliveryLeavesNetOfCommissionInTheWallet(): void
    {
        $this->deliverCashOrder(2); // 2 x 60.00 + 50.00 delivery = 170.00, 12.00 commission

        $this->assertSame(15800, (new WalletService())->balanceCents((int) $this->vendor['id']));
    }

    public function testLedgerRecordsRunningBalance(): void
    {
        $this->deliverCashOrder(2);

        $rows = Db::all(
            'SELECT w.amount, w.balance_after, t.name
             FROM wallet_transactions w JOIN transaction_types t ON t.id = w.type_id
             WHERE w.vendor_id = ? ORDER BY w.id',
            [$this->vendor['id']]
        );

        $this->assertCount(2, $rows);
        $this->assertSame('Order earning', $rows[0]['name']);
        $this->assertSame('170.00', $rows[0]['amount']);
        $this->assertSame('170.00', $rows[0]['balance_after']);
        $this->assertSame('Commission', $rows[1]['name']);
        $this->assertSame('-12.00', $rows[1]['amount']);
        $this->assertSame('158.00', $rows[1]['balance_after']);
    }

    public function testEarningsSummaryIsScopedToTheVendor(): void
    {
        $this->deliverCashOrder(2);
        $other = $this->createVendor();

        $wallet = new WalletService();
        $summary = $wallet->summary((int) $this->vendor['id']);

        $this->assertSame('158.00', $summary['balance']);
        $this->assertSame('170.00', $summary['earned_today']);
        $this->assertSame('170.00', $summary['earned_week']);
        $this->assertSame(0, $wallet->balanceCents((int) $other['id']));
    }

    public function testDuplicateCreditGuardRecognisesAnAlreadyCreditedOrder(): void
    {
        $orderId = $this->deliverCashOrder(1);
        $wallet = new WalletService();

        $this->assertTrue($wallet->hasEntry((int) $this->vendor['id'], $orderId, 'Order earning'));
        $this->assertFalse($wallet->hasEntry((int) $this->vendor['id'], $orderId, 'Refund'));
    }

    public function testPayoutWithinTheBalanceIsCreatedAsRequested(): void
    {
        $this->deliverCashOrder(2);

        $payout = (new PayoutService())->request((int) $this->vendor['id'], ['amount' => '100.00']);

        $this->assertSame('100.00', $payout['amount']);
        $this->assertSame('Requested', $payout['status']);
    }

    public function testPayoutCannotExceedTheAvailableBalance(): void
    {
        $this->deliverCashOrder(2);

        $error = $this->assertHttpStatus(
            422,
            fn () => (new PayoutService())->request((int) $this->vendor['id'], ['amount' => '500.00'])
        );

        $this->assertArrayHasKey('amount', $error->fields);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM payouts WHERE vendor_id = ?', [$this->vendor['id']]));
    }

    public function testPendingPayoutsReduceWhatIsAvailable(): void
    {
        $this->deliverCashOrder(2);
        $payouts = new PayoutService();
        $payouts->request((int) $this->vendor['id'], ['amount' => '100.00']);

        $summary = (new WalletService())->summary((int) $this->vendor['id']);

        $this->assertSame('158.00', $summary['balance']);
        $this->assertSame('100.00', $summary['pending_payouts']);
        $this->assertSame('58.00', $summary['available']);

        $this->assertHttpStatus(422, fn () => $payouts->request((int) $this->vendor['id'], ['amount' => '60.00']));
        $payouts->request((int) $this->vendor['id'], ['amount' => '58.00']);
        $this->assertSame('0.00', (new WalletService())->summary((int) $this->vendor['id'])['available']);
    }

    public function testPayoutRequiresAPositiveAmount(): void
    {
        $this->deliverCashOrder(1);

        $this->assertHttpStatus(422, fn () => (new PayoutService())->request((int) $this->vendor['id'], ['amount' => '0']));
        $this->assertHttpStatus(422, fn () => (new PayoutService())->request((int) $this->vendor['id'], ['amount' => '-5']));
        $this->assertHttpStatus(422, fn () => (new PayoutService())->request((int) $this->vendor['id'], ['amount' => 'abc']));
    }

    public function testVendorWithNoEarningsCannotRequestAPayout(): void
    {
        $this->assertHttpStatus(422, fn () => (new PayoutService())->request((int) $this->vendor['id'], ['amount' => '10']));
    }

    public function testPayoutListIsScopedToTheVendor(): void
    {
        $this->deliverCashOrder(2);
        $other = $this->createVendor();

        $payouts = new PayoutService();
        $payouts->request((int) $this->vendor['id'], ['amount' => '50.00']);

        $this->assertCount(1, $payouts->listForVendor((int) $this->vendor['id']));
        $this->assertCount(0, $payouts->listForVendor((int) $other['id']));
    }

    public function testMoneyNeverLandsInTheWrongWallet(): void
    {
        $orderId = $this->deliverCashOrder(2);
        $other = $this->createVendor();

        $otherEntries = (int) Db::value(
            'SELECT COUNT(*) FROM wallet_transactions WHERE vendor_id = ? AND order_id = ?',
            [$other['id'], $orderId]
        );

        $this->assertSame(0, $otherEntries);
        $this->assertSame(
            Money::decimal(15800),
            Money::decimal((new WalletService())->balanceCents((int) $this->vendor['id']))
        );
    }
}
