<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Geo;
use MajiLink\Support\Money;
use MajiLink\Support\Validator;

final class OrderService
{
    /** One map defines every legal status move; anything else is rejected. */
    private const TRANSITIONS = [
        'Pending'    => ['Accepted', 'Declined', 'Cancelled', 'Expired'],
        'Accepted'   => ['On the way', 'Delivered', 'Cancelled'],
        'On the way' => ['Delivered', 'Cancelled'],
        'Delivered'  => [],
        'Declined'   => [],
        'Cancelled'  => [],
        'Expired'    => [],
    ];

    public function __construct(
        private readonly SettingsService $settings = new SettingsService(),
        private readonly PricingService $pricing = new PricingService(),
        private readonly WalletService $wallet = new WalletService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditService $audit = new AuditService()
    ) {
    }

    public function create(int $customerId, array $data): array
    {
        $input = Validator::validate($data, [
            'vendor_id'   => 'required|integer|exists:vendors,id',
            'location_id' => 'required|integer|exists:locations,id',
            'quantity'    => 'required|integer',
            'note'        => 'nullable|string|max:255',
            'payment_method' => 'nullable|in:Cash,M-Pesa',
        ]);

        $location = Db::one('SELECT * FROM locations WHERE id = ? AND user_id = ?', [$input['location_id'], $customerId]);
        if ($location === null) {
            throw HttpException::notFound('Delivery pin not found.');
        }

        $vendor = Db::one(
            'SELECT v.*, s.name AS status, u.id AS vendor_user_id, u.phone AS vendor_phone
             FROM vendors v
             JOIN vendor_application_statuses s ON s.id = v.application_status_id
             JOIN users u ON u.id = v.user_id
             WHERE v.id = ?',
            [$input['vendor_id']]
        );
        if ($vendor === null || $vendor['status'] !== 'Approved') {
            throw HttpException::conflict('That vendor is not available.');
        }
        if ((int) $vendor['is_online'] !== 1 || $vendor['last_heartbeat_at'] === null
            || strtotime($vendor['last_heartbeat_at'] . ' UTC') < strtotime($this->settings->heartbeatCutoff() . ' UTC')) {
            throw HttpException::conflict('That vendor just went offline. Pick another vendor.');
        }

        $quantity = (int) $input['quantity'];
        $quote = $this->pricing->quote($vendor, (float) $location['lat'], (float) $location['lng'], $quantity);

        $maxRadius = $this->settings->float('max_radius_km', 5);
        if ($quote['distance_km'] > $maxRadius || $quote['distance_km'] > (float) $vendor['delivery_radius_km']) {
            throw HttpException::validation(['vendor_id' => 'That vendor does not deliver to this pin.']);
        }

        $paymentMethodName = $input['payment_method'] ?? 'Cash';
        $paymentMethodId = (int) Db::value('SELECT id FROM payment_methods WHERE name = ?', [$paymentMethodName]);
        $unpaidId = (int) Db::value('SELECT id FROM payment_statuses WHERE name = ?', ['Unpaid']);
        $pendingStatusId = $this->statusId('Pending');
        $expiryMinutes = max(1, $this->settings->int('order_expiry_minutes', 5));

        $orderId = Db::transaction(function () use (
            $customerId, $vendor, $location, $quantity, $quote, $input,
            $paymentMethodId, $unpaidId, $pendingStatusId, $expiryMinutes
        ): int {
            $id = Db::insert('orders', [
                'order_no'          => 'TMP-' . bin2hex(random_bytes(8)),
                'customer_id'       => $customerId,
                'vendor_id'         => $vendor['id'],
                'location_id'       => $location['id'],
                'status_id'         => $pendingStatusId,
                'quantity'          => $quantity,
                'unit_price'        => $quote['unit_price'],
                'delivery_fee'      => $quote['delivery_fee'],
                'total'             => $quote['total'],
                'commission_amount' => $quote['commission_amount'],
                'delivery_lat'      => $location['lat'],
                'delivery_lng'      => $location['lng'],
                'delivery_alias'    => $location['alias'],
                'note'              => $input['note'] ?? null,
                'requested_at'      => gmdate('Y-m-d H:i:s'),
                'expires_at'        => gmdate('Y-m-d H:i:s', time() + $expiryMinutes * 60),
            ]);
            Db::update('orders', ['order_no' => 'ML-' . (100000 + $id)], 'id = ?', [$id]);
            Db::insert('order_status_history', [
                'order_id'   => $id,
                'status_id'  => $pendingStatusId,
                'changed_by' => $customerId,
                'note'       => 'Order placed',
            ]);
            Db::insert('payments', [
                'order_id'  => $id,
                'method_id' => $paymentMethodId,
                'status_id' => $unpaidId,
                'amount'    => $quote['total'],
            ]);
            return $id;
        });

        $order = $this->row($orderId);
        $this->notifications->notifyVendorUser(
            (int) $vendor['vendor_user_id'],
            'New water order',
            sprintf('%s · %d jerrycan(s) · KES %s · %.1f km away', $order['customer_first_name'], $quantity, $quote['total'], $quote['distance_km']),
            ['type' => 'order_created', 'order_id' => (string) $orderId]
        );

        return $this->present($order, 'customer', $quote['distance_km']);
    }

    public function listForCustomer(int $customerId, ?string $filter = null): array
    {
        $sql = 'SELECT o.id FROM orders o JOIN order_statuses s ON s.id = o.status_id WHERE o.customer_id = ?';
        $params = [$customerId];
        if ($filter === 'active') {
            $sql .= ' AND s.is_final = 0';
        } elseif ($filter === 'history') {
            $sql .= ' AND s.is_final = 1';
        }
        $sql .= ' ORDER BY o.id DESC LIMIT 100';
        $rows = Db::all($sql, $params);
        return array_map(fn (array $row): array => $this->present($this->row((int) $row['id']), 'customer'), $rows);
    }

    public function listForVendor(int $vendorId, ?string $filter = null): array
    {
        $sql = 'SELECT o.id FROM orders o JOIN order_statuses s ON s.id = o.status_id WHERE o.vendor_id = ?';
        $params = [$vendorId];
        if ($filter === 'active') {
            $sql .= ' AND s.is_final = 0';
        } elseif ($filter === 'today') {
            $sql .= ' AND o.created_at >= UTC_DATE()';
        } elseif ($filter === 'week') {
            $sql .= ' AND o.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)';
        }
        $sql .= ' ORDER BY FIELD(s.name, "Pending", "Accepted", "On the way") DESC, o.id DESC LIMIT 100';
        $rows = Db::all($sql, $params);
        return array_map(fn (array $row): array => $this->present($this->row((int) $row['id']), 'vendor'), $rows);
    }

    public function forCustomer(int $customerId, int $orderId): array
    {
        $order = $this->row($orderId);
        if ((int) $order['customer_id'] !== $customerId) {
            throw HttpException::notFound('Order not found.');
        }
        return $this->present($order, 'customer');
    }

    public function forVendor(int $vendorId, int $orderId): array
    {
        $order = $this->row($orderId);
        if ((int) $order['vendor_id'] !== $vendorId) {
            throw HttpException::notFound('Order not found.');
        }
        return $this->present($order, 'vendor');
    }

    public function accept(int $vendorId, int $orderId): array
    {
        $order = $this->guardVendorOrder($vendorId, $orderId);
        $this->assertNotExpired($order);
        $this->transition($order, 'Accepted', (int) $order['vendor_user_id'], null);
        Db::update('orders', ['accepted_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$orderId]);

        $this->notifications->send(
            (int) $order['customer_id'],
            'Order accepted',
            $order['business_name'] . ' accepted your order and will call if needed.',
            ['type' => 'order_accepted', 'order_id' => (string) $orderId]
        );
        return $this->present($this->row($orderId), 'vendor');
    }

    public function decline(int $vendorId, int $orderId, ?string $reason = null): array
    {
        $order = $this->guardVendorOrder($vendorId, $orderId);
        $this->transition($order, 'Declined', (int) $order['vendor_user_id'], $reason);
        $this->notifications->send(
            (int) $order['customer_id'],
            'Order declined',
            $order['business_name'] . ' could not take your order. Please choose another vendor.',
            ['type' => 'order_declined', 'order_id' => (string) $orderId]
        );
        return $this->present($this->row($orderId), 'vendor');
    }

    public function markOnTheWay(int $vendorId, int $orderId): array
    {
        $order = $this->guardVendorOrder($vendorId, $orderId);
        $this->transition($order, 'On the way', (int) $order['vendor_user_id'], null);
        $this->notifications->send(
            (int) $order['customer_id'],
            'On the way',
            $order['business_name'] . ' is heading to your gate.',
            ['type' => 'order_on_the_way', 'order_id' => (string) $orderId]
        );
        return $this->present($this->row($orderId), 'vendor');
    }

    public function markDelivered(int $vendorId, int $orderId): array
    {
        $order = $this->guardVendorOrder($vendorId, $orderId);
        $this->transition($order, 'Delivered', (int) $order['vendor_user_id'], null);
        $this->afterDelivery($order);
        return $this->present($this->row($orderId), 'vendor');
    }

    public function cancelByCustomer(int $customerId, int $orderId, ?string $reason = null): array
    {
        $order = $this->row($orderId);
        if ((int) $order['customer_id'] !== $customerId) {
            throw HttpException::notFound('Order not found.');
        }
        if (!in_array($order['status_name'], ['Pending', 'Accepted'], true)) {
            throw HttpException::conflict('This order can no longer be cancelled.');
        }
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : 'Cancelled by customer';
        $this->transition($order, 'Cancelled', $customerId, $reason);
        Db::update('orders', ['cancelled_by' => $customerId, 'cancel_reason' => $reason], 'id = ?', [$orderId]);

        $this->notifications->notifyVendorUser(
            (int) $order['vendor_user_id'],
            'Order cancelled',
            $order['customer_first_name'] . ' cancelled ' . $order['order_no'] . '.',
            ['type' => 'order_cancelled', 'order_id' => (string) $orderId]
        );
        return $this->present($this->row($orderId), 'customer');
    }

    public function cancelByVendor(int $vendorId, int $orderId, string $reason): array
    {
        $order = $this->guardVendorOrder($vendorId, $orderId);
        if (!in_array($order['status_name'], ['Accepted', 'On the way'], true)) {
            throw HttpException::conflict('Cancel before pickup is not allowed here; decline the order instead.');
        }
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => 'A cancellation reason is required.']);
        }
        $this->transition($order, 'Cancelled', (int) $order['vendor_user_id'], $reason);
        Db::update('orders', [
            'cancelled_by' => $order['vendor_user_id'],
            'cancel_reason'=> mb_substr(trim($reason), 0, 255),
        ], 'id = ?', [$orderId]);

        $this->notifications->send(
            (int) $order['customer_id'],
            'Vendor cancelled',
            $order['business_name'] . ' cancelled your order. Reason: ' . $reason,
            ['type' => 'order_cancelled', 'order_id' => (string) $orderId]
        );
        return $this->present($this->row($orderId), 'vendor');
    }

    /** Admin override: any status is reachable, but a reason is mandatory and logged. */
    public function override(int $orderId, int $adminId, string $status, string $reason, ?string $ip = null): array
    {
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => 'A reason is required for an override.']);
        }
        $order = $this->row($orderId);
        if ($order['status_name'] === $status) {
            throw HttpException::conflict('The order is already ' . $status . '.');
        }
        $statusId = $this->statusId($status);
        Db::transaction(function () use ($order, $statusId, $adminId, $reason): void {
            Db::update('orders', ['status_id' => $statusId], 'id = ?', [$order['id']]);
            Db::insert('order_status_history', [
                'order_id'   => $order['id'],
                'status_id'  => $statusId,
                'changed_by' => $adminId,
                'note'       => 'Admin override: ' . $reason,
            ]);
        });
        if ($status === 'Delivered') {
            Db::update('orders', ['delivered_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$order['id']]);
            $this->afterDelivery($order);
        }
        if ($status === 'Cancelled') {
            Db::update('orders', ['cancelled_by' => $adminId, 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$order['id']]);
        }
        $this->audit->record($adminId, 'orders.override_status', 'orders', $orderId, [
            'from' => $order['status_name'], 'to' => $status, 'reason' => $reason,
        ], $ip);
        $this->notifications->send(
            (int) $order['customer_id'],
            'Order updated',
            'Your order ' . $order['order_no'] . ' is now ' . strtolower($status) . '.',
            ['type' => 'order_override', 'order_id' => (string) $orderId]
        );
        return $this->present($this->row($orderId), 'admin');
    }

    /** Cron: expire pending orders whose window has passed. */
    public function expireStale(): int
    {
        $rows = Db::all(
            "SELECT o.id, o.customer_id, o.order_no, v.business_name
             FROM orders o
             JOIN order_statuses s ON s.id = o.status_id
             JOIN vendors v ON v.id = o.vendor_id
             WHERE s.name = 'Pending' AND o.expires_at IS NOT NULL AND o.expires_at < UTC_TIMESTAMP()"
        );
        foreach ($rows as $row) {
            $this->transition(['id' => $row['id'], 'status_name' => 'Pending'], 'Expired', null, 'Expired automatically');
            $this->notifications->send(
                (int) $row['customer_id'],
                'Order expired',
                $row['business_name'] . ' did not respond. Choose another vendor to keep your delivery on track.',
                ['type' => 'order_expired', 'order_id' => (string) $row['id']]
            );
        }
        return count($rows);
    }

    public function listForAdmin(?string $status = null, ?string $search = null, ?string $from = null, ?string $to = null): array
    {
        $sql = 'SELECT o.id, o.order_no, o.quantity, o.total, o.commission_amount, o.created_at,
                       o.delivery_alias, s.name AS status, s.is_final,
                       CONCAT(cu.first_name, " ", cu.last_name) AS customer_name, cu.phone AS customer_phone,
                       v.business_name, pm.name AS payment_method, ps.name AS payment_status
                FROM orders o
                JOIN order_statuses s ON s.id = o.status_id
                JOIN users cu ON cu.id = o.customer_id
                JOIN vendors v ON v.id = o.vendor_id
                LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
                LEFT JOIN payment_methods pm ON pm.id = p.method_id
                LEFT JOIN payment_statuses ps ON ps.id = p.status_id
                WHERE 1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND s.name = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (o.order_no LIKE ? OR cu.phone LIKE ? OR cu.first_name LIKE ? OR v.business_name LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        if ($from !== null && $from !== '') {
            $sql .= ' AND o.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $sql .= ' AND o.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $sql .= ' ORDER BY o.id DESC LIMIT 200';
        return Db::all($sql, $params);
    }

    public function row(int $orderId): array
    {
        $row = Db::one(
            'SELECT o.*, st.name AS status_name, st.is_final,
                    cu.first_name AS customer_first_name, cu.last_name AS customer_last_name,
                    cu.phone AS customer_phone, cu.id AS customer_user_id,
                    v.business_name, v.base_lat AS vendor_lat, v.base_lng AS vendor_lng,
                    vu.phone AS vendor_phone, vu.id AS vendor_user_id,
                    pm.name AS payment_method, ps.name AS payment_status,
                    pay.mpesa_receipt, pay.id AS payment_id,
                    r.stars AS rating_stars, r.comment AS rating_comment
             FROM orders o
             JOIN order_statuses st ON st.id = o.status_id
             JOIN users cu ON cu.id = o.customer_id
             JOIN vendors v ON v.id = o.vendor_id
             JOIN users vu ON vu.id = v.user_id
             LEFT JOIN payments pay ON pay.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
             LEFT JOIN payment_methods pm ON pm.id = pay.method_id
             LEFT JOIN payment_statuses ps ON ps.id = pay.status_id
             LEFT JOIN ratings r ON r.order_id = o.id
             WHERE o.id = ?',
            [$orderId]
        );
        if ($row === null) {
            throw HttpException::notFound('Order not found.');
        }
        return $row;
    }

    public function history(int $orderId): array
    {
        return Db::all(
            'SELECT h.id, s.name AS status, h.note, h.created_at,
                    CONCAT(u.first_name, " ", u.last_name) AS changed_by
             FROM order_status_history h
             JOIN order_statuses s ON s.id = h.status_id
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.order_id = ? ORDER BY h.id ASC',
            [$orderId]
        );
    }

    public function present(array $order, string $viewer, ?float $distanceKm = null): array
    {
        $distance = $distanceKm ?? Geo::distanceKm(
            (float) $order['vendor_lat'],
            (float) $order['vendor_lng'],
            (float) $order['delivery_lat'],
            (float) $order['delivery_lng']
        );
        $accepted = $order['accepted_at'] !== null;

        // Privacy rule: until a vendor accepts, they only see a first name, the
        // quantity, the total and an approximate distance.
        $hideDetails = $viewer === 'vendor' && !$accepted;
        $statusName = $order['status_name'];

        $payload = [
            'id'         => (int) $order['id'],
            'order_no'   => $order['order_no'],
            'status'     => ['name' => $statusName, 'is_final' => (bool) $order['is_final']],
            'quantity'   => (int) $order['quantity'],
            'unit_price' => $order['unit_price'],
            'delivery_fee' => $order['delivery_fee'],
            'total'      => $order['total'],
            'requested_at' => $order['requested_at'],
            'accepted_at'  => $order['accepted_at'],
            'delivered_at' => $order['delivered_at'],
            'expires_at'   => $order['expires_at'],
            'payment'    => [
                'method' => $order['payment_method'],
                'status' => $order['payment_status'],
                'receipt'=> $order['mpesa_receipt'],
            ],
            'distance_km'=> $hideDetails ? round($distance, 1) : round($distance, 2),
            'rating'     => $order['rating_stars'] === null ? null : [
                'stars' => (int) $order['rating_stars'], 'comment' => $order['rating_comment'],
            ],
            'customer'   => [
                'id'         => $hideDetails ? null : (int) $order['customer_user_id'],
                'first_name' => $order['customer_first_name'],
                'last_name'  => $hideDetails ? null : $order['customer_last_name'],
                'phone'      => $hideDetails ? null : $order['customer_phone'],
            ],
            'vendor'     => [
                'id'            => (int) $order['vendor_id'],
                'business_name' => $order['business_name'],
                'phone'         => $order['vendor_phone'],
                'base_lat'      => (float) $order['vendor_lat'],
                'base_lng'      => (float) $order['vendor_lng'],
            ],
            'delivery'   => $hideDetails ? [
                'alias' => null, 'lat' => null, 'lng' => null,
            ] : [
                'alias' => $order['delivery_alias'],
                'lat'   => (float) $order['delivery_lat'],
                'lng'   => (float) $order['delivery_lng'],
            ],
            'note'       => $hideDetails ? null : $order['note'],
        ];

        if ($viewer === 'admin') {
            $payload['commission_amount'] = $order['commission_amount'];
            $payload['cancel_reason'] = $order['cancel_reason'];
        }

        if ($statusName === 'Pending' && $order['expires_at'] !== null) {
            $payload['expires_in_seconds'] = max(0, strtotime($order['expires_at'] . ' UTC') - time());
        }

        $payload['actions'] = $this->actions($order, $viewer, $accepted);
        return $payload;
    }

    private function actions(array $order, string $viewer, bool $accepted): array
    {
        $status = $order['status_name'];
        if ($viewer === 'customer') {
            return [
                'cancel'  => in_array($status, ['Pending', 'Accepted'], true),
                'rate'    => $status === 'Delivered' && $order['rating_stars'] === null,
                'call_vendor' => in_array($status, ['Accepted', 'On the way'], true),
                'pay_mpesa'   => $status !== 'Delivered' && ($order['payment_method'] ?? '') === 'M-Pesa'
                    && in_array($order['payment_status'] ?? '', ['Unpaid', 'Failed', 'Pending'], true),
            ];
        }
        if ($viewer === 'vendor') {
            return [
                'accept'  => $status === 'Pending',
                'decline' => $status === 'Pending',
                'on_the_way' => $status === 'Accepted',
                'delivered'  => in_array($status, ['Accepted', 'On the way'], true),
                'navigate'   => $accepted && !in_array($status, ['Delivered', 'Cancelled', 'Declined', 'Expired'], true),
                'call_customer' => $accepted && !in_array($status, ['Delivered'], true),
            ];
        }
        return [];
    }

    private function transition(array $order, string $to, ?int $by, ?string $note): void
    {
        $from = $order['status_name'];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw HttpException::conflict("Cannot move an order from {$from} to {$to}.");
        }
        $statusId = $this->statusId($to);
        Db::transaction(function () use ($order, $statusId, $by, $note): void {
            Db::update('orders', ['status_id' => $statusId], 'id = ?', [$order['id']]);
            Db::insert('order_status_history', [
                'order_id'   => $order['id'],
                'status_id'  => $statusId,
                'changed_by' => $by,
                'note'       => $note,
            ]);
        });
    }

    private function afterDelivery(array $order): void
    {
        $orderId = (int) $order['id'];
        $vendorId = (int) $order['vendor_id'];
        $gross = Money::cents($order['unit_price']) * (int) $order['quantity'] + Money::cents($order['delivery_fee']);
        $commission = Money::cents($order['commission_amount']);

        if (($order['payment_method'] ?? 'Cash') === 'Cash') {
            if (!$this->wallet->hasEntry($vendorId, $orderId, 'Order earning')) {
                $this->wallet->credit($vendorId, 'Order earning', $orderId, $gross, 'Cash order ' . $order['order_no']);
                $this->wallet->debit($vendorId, 'Commission', $orderId, $commission, 'Commission on ' . $order['order_no']);
            }
        }

        Db::update('orders', ['delivered_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$orderId]);
        $this->notifications->send(
            (int) $order['customer_id'],
            'Delivered',
            'Your water has arrived. Rate ' . $order['business_name'] . ' to help your neighbours.',
            ['type' => 'order_delivered', 'order_id' => (string) $orderId]
        );
    }

    private function guardVendorOrder(int $vendorId, int $orderId): array
    {
        $order = $this->row($orderId);
        if ((int) $order['vendor_id'] !== $vendorId) {
            throw HttpException::notFound('Order not found.');
        }
        return $order;
    }

    private function assertNotExpired(array $order): void
    {
        if ($order['status_name'] === 'Pending' && $order['expires_at'] !== null
            && strtotime($order['expires_at'] . ' UTC') < time()) {
            $this->transition($order, 'Expired', null, 'Expired automatically');
            throw HttpException::conflict('This order already expired.');
        }
    }

    public function statusId(string $name): int
    {
        $id = Db::value('SELECT id FROM order_statuses WHERE name = ?', [$name]);
        if ($id === null) {
            throw new \RuntimeException('Unknown order status: ' . $name);
        }
        return (int) $id;
    }

    public function statuses(): array
    {
        return Db::all('SELECT * FROM order_statuses ORDER BY sort_order');
    }
}
