<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Money;
use MajiLink\Support\Validator;

final class PaymentService
{
    public function __construct(
        private readonly MpesaService $mpesa = new MpesaService(),
        private readonly WalletService $wallet = new WalletService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditService $audit = new AuditService()
    ) {
    }

    public function initiateStk(int $customerId, int $orderId, ?string $phone): array
    {
        $order = (new OrderService())->row($orderId);
        if ((int) $order['customer_id'] !== $customerId) {
            throw HttpException::notFound('Order not found.');
        }
        if ($order['status_name'] === 'Delivered' || $order['status_name'] === 'Cancelled') {
            throw HttpException::conflict('This order is closed.');
        }
        if (($order['payment_method'] ?? '') !== 'M-Pesa') {
            throw HttpException::conflict('This order is set to cash payment.');
        }
        if (($order['payment_status'] ?? '') === 'Paid') {
            throw HttpException::conflict('This order is already paid.');
        }

        $msisdn = $phone ?? $order['customer_phone'];
        if (!preg_match('/^(\+?254|0)[17]\d{8}$/', (string) $msisdn) && !preg_match('/^(254|\+254)[17]\d{8}$/', (string) $msisdn)) {
            // OrderService stores the customer's saved phone; normalise anything else.
            $normalised = (new OtpService())->normalize((string) $msisdn);
            if (!preg_match('/^\+254[17]\d{8}$/', $normalised)) {
                throw HttpException::validation(['phone' => 'Enter a valid M-Pesa phone number.']);
            }
            $msisdn = $normalised;
        }

        $push = $this->mpesa->stkPush((string) $msisdn, Money::cents($order['total']), $order['order_no'], 'MajiLink water delivery');
        $pendingId = (int) Db::value('SELECT id FROM payment_statuses WHERE name = ?', ['Pending']);

        Db::transaction(function () use ($order, $msisdn, $push, $pendingId): void {
            Db::update('payments', [
                'status_id'                 => $pendingId,
                'phone'                     => (string) $msisdn,
                'mpesa_checkout_request_id' => $push['checkout_request_id'],
            ], 'id = ?', [$order['payment_id']]);
        });

        return [
            'payment_id'         => (int) $order['payment_id'],
            'checkout_request_id'=> $push['checkout_request_id'],
            'message'            => $push['customer_message'],
            'simulated'          => $push['simulated'],
            'amount'             => $order['total'],
        ];
    }

    /**
     * Daraja callback. Idempotent by CheckoutRequestID: a repeated callback for
     * an already-paid payment is acknowledged without double-crediting the wallet.
     */
    public function handleCallback(array $payload): array
    {
        $body = $payload['Body']['stkCallback'] ?? $payload['Body'] ?? null;
        if (!is_array($body)) {
            throw HttpException::badRequest('Malformed M-Pesa callback.');
        }
        $checkoutId = (string) ($body['CheckoutRequestID'] ?? '');
        if ($checkoutId === '') {
            throw HttpException::badRequest('Callback is missing CheckoutRequestID.');
        }

        $payment = Db::one('SELECT * FROM payments WHERE mpesa_checkout_request_id = ?', [$checkoutId]);
        if ($payment === null) {
            // Acknowledge unknown callbacks so Safaricom stops retrying.
            return ['ok' => true, 'ignored' => true];
        }
        $statusName = (string) Db::value('SELECT name FROM payment_statuses WHERE id = ?', [$payment['status_id']]);
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($statusName === 'Paid') {
            return ['ok' => true, 'duplicate' => true];
        }

        $resultCode = (int) ($body['ResultCode'] ?? 1);
        $paidId = (int) Db::value('SELECT id FROM payment_statuses WHERE name = ?', ['Paid']);
        $failedId = (int) Db::value('SELECT id FROM payment_statuses WHERE name = ?', ['Failed']);

        if ($resultCode !== 0) {
            Db::update('payments', ['status_id' => $failedId, 'raw_callback' => $raw], 'id = ?', [$payment['id']]);
            $customerId = Db::value('SELECT customer_id FROM orders WHERE id = ?', [$payment['order_id']]);
            if ($customerId !== null) {
                $this->notifications->send(
                    (int) $customerId,
                    'M-Pesa payment failed',
                    'We could not confirm your payment. Try again or pay cash on delivery.',
                    ['type' => 'payment_failed', 'order_id' => (string) $payment['order_id']]
                );
            }
            return ['ok' => true, 'status' => 'Failed'];
        }

        $items = [];
        foreach ($body['CallbackMetadata']['Item'] ?? [] as $item) {
            if (isset($item['Name'])) {
                $items[$item['Name']] = $item['Value'] ?? null;
            }
        }
        $receipt = isset($items['MpesaReceiptNumber']) ? (string) $items['MpesaReceiptNumber'] : null;

        Db::transaction(function () use ($payment, $paidId, $receipt, $raw): void {
            Db::update('payments', [
                'status_id'    => $paidId,
                'mpesa_receipt'=> $receipt,
                'raw_callback' => $raw,
            ], 'id = ?', [$payment['id']]);
        });

        $this->creditVendorForPaidOrder((int) $payment['order_id']);
        return ['ok' => true, 'status' => 'Paid', 'receipt' => $receipt];
    }

    /** Used by the admin portal to settle a stuck callback manually. */
    public function markPaidManually(int $paymentId, int $adminId, ?string $receipt, ?string $ip = null): void
    {
        $payment = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($payment === null) {
            throw HttpException::notFound('Payment not found.');
        }
        $statusName = (string) Db::value('SELECT name FROM payment_statuses WHERE id = ?', [$payment['status_id']]);
        if ($statusName === 'Paid') {
            throw HttpException::conflict('That payment is already paid.');
        }
        $paidId = (int) Db::value('SELECT id FROM payment_statuses WHERE name = ?', ['Paid']);
        Db::update('payments', [
            'status_id'     => $paidId,
            'mpesa_receipt' => $receipt,
        ], 'id = ?', [$paymentId]);
        $this->creditVendorForPaidOrder((int) $payment['order_id']);
        $this->audit->record($adminId, 'payments.mark_paid', 'payments', $paymentId, ['receipt' => $receipt], $ip);
    }

    public function refund(int $paymentId, int $adminId, ?string $reason, ?string $ip = null): void
    {
        $payment = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($payment === null) {
            throw HttpException::notFound('Payment not found.');
        }
        $statusName = (string) Db::value('SELECT name FROM payment_statuses WHERE id = ?', [$payment['status_id']]);
        if ($statusName !== 'Paid') {
            throw HttpException::conflict('Only paid payments can be refunded.');
        }
        $refundedId = (int) Db::value('SELECT id FROM payment_statuses WHERE name = ?', ['Refunded']);
        Db::update('payments', ['status_id' => $refundedId], 'id = ?', [$paymentId]);

        $order = Db::one('SELECT * FROM orders WHERE id = ?', [$payment['order_id']]);
        if ($order !== null) {
            $this->wallet->debit(
                (int) $order['vendor_id'],
                'Refund',
                (int) $order['id'],
                Money::cents($payment['amount']),
                'Refund for ' . $order['order_no'] . ($reason ? ' — ' . $reason : '')
            );
        }
        $this->audit->record($adminId, 'payments.refund', 'payments', $paymentId, ['reason' => $reason], $ip);
    }

    public function listForAdmin(?string $status = null, ?string $method = null, ?string $search = null): array
    {
        $sql = 'SELECT p.*, ps.name AS status, pm.name AS method, o.order_no, o.total AS order_total,
                       CONCAT(u.first_name, " ", u.last_name) AS customer_name, v.business_name
                FROM payments p
                JOIN payment_statuses ps ON ps.id = p.status_id
                JOIN payment_methods pm ON pm.id = p.method_id
                JOIN orders o ON o.id = p.order_id
                JOIN users u ON u.id = o.customer_id
                JOIN vendors v ON v.id = o.vendor_id
                WHERE 1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND ps.name = ?';
            $params[] = $status;
        }
        if ($method !== null && $method !== '') {
            $sql .= ' AND pm.name = ?';
            $params[] = $method;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (o.order_no LIKE ? OR p.mpesa_receipt LIKE ? OR p.mpesa_checkout_request_id LIKE ? OR u.phone LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        $sql .= ' ORDER BY p.id DESC LIMIT 200';
        return Db::all($sql, $params);
    }

    private function creditVendorForPaidOrder(int $orderId): void
    {
        $order = Db::one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            return;
        }
        if ($this->wallet->hasEntry((int) $order['vendor_id'], $orderId, 'Order earning')) {
            return;
        }
        $gross = Money::cents($order['unit_price']) * (int) $order['quantity'] + Money::cents($order['delivery_fee']);
        $commission = Money::cents($order['commission_amount']);

        // Net earnings are credited minus commission, per the M-Pesa rule.
        $this->wallet->credit((int) $order['vendor_id'], 'Order earning', $orderId, $gross - $commission, 'M-Pesa earnings for ' . $order['order_no']);

        $this->notifications->send(
            (int) $order['customer_id'],
            'Payment received',
            'M-Pesa confirmed KES ' . $order['total'] . ' for ' . $order['order_no'] . '.',
            ['type' => 'payment_paid', 'order_id' => (string) $orderId]
        );
        $vendorUser = Db::value('SELECT user_id FROM vendors WHERE id = ?', [$order['vendor_id']]);
        if ($vendorUser !== null) {
            $this->notifications->notifyVendorUser(
                (int) $vendorUser,
                'Payment received',
                'KES ' . $order['total'] . ' paid by M-Pesa for ' . $order['order_no'] . '.',
                ['type' => 'payment_paid', 'order_id' => (string) $orderId]
            );
        }
    }
}
