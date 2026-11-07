<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Validator;

final class RecurringService
{
    public function __construct(
        private readonly PricingService $pricing = new PricingService(),
        private readonly NotificationService $notifications = new NotificationService()
    ) {
    }

    public function list(int $customerId): array
    {
        $rows = Db::all(
            'SELECT r.*, f.name AS frequency, f.interval_days, v.business_name, v.is_online,
                    l.alias AS location_alias, l.lat, l.lng, v.price_per_20l
             FROM recurring_orders r
             JOIN frequencies f ON f.id = r.frequency_id
             JOIN vendors v ON v.id = r.vendor_id
             JOIN locations l ON l.id = r.location_id
             WHERE r.customer_id = ?
             ORDER BY r.is_active DESC, r.next_run_at ASC',
            [$customerId]
        );
        foreach ($rows as &$row) {
            $row['is_active'] = (bool) $row['is_active'];
            $row['estimated_total'] = number_format(
                (float) $row['price_per_20l'] * (int) $row['quantity'],
                2,
                '.',
                ''
            );
        }
        return $rows;
    }

    public function create(int $customerId, array $data): array
    {
        $input = Validator::validate($data, [
            'vendor_id'    => 'required|integer|exists:vendors,id',
            'location_id'  => 'required|integer|exists:locations,id',
            'frequency_id' => 'required|integer|exists:frequencies,id',
            'quantity'     => 'required|integer',
            'next_run_at'  => 'nullable|string',
        ]);
        $this->pricing->assertQuantity((int) $input['quantity']);

        $location = Db::one('SELECT * FROM locations WHERE id = ? AND user_id = ?', [$input['location_id'], $customerId]);
        if ($location === null) {
            throw HttpException::notFound('Delivery pin not found.');
        }
        $frequency = Db::one('SELECT * FROM frequencies WHERE id = ?', [$input['frequency_id']]);
        if ($frequency === null) {
            throw HttpException::notFound('Frequency not found.');
        }

        $nextRun = $input['next_run_at'] ?? null;
        $nextRunAt = $nextRun !== null && strtotime($nextRun) !== false
            ? gmdate('Y-m-d H:i:s', max(time() + 3600, strtotime($nextRun)))
            : gmdate('Y-m-d H:i:s', time() + 86400);

        $id = Db::insert('recurring_orders', [
            'customer_id' => $customerId,
            'vendor_id'   => (int) $input['vendor_id'],
            'location_id' => (int) $input['location_id'],
            'frequency_id'=> (int) $input['frequency_id'],
            'quantity'    => (int) $input['quantity'],
            'next_run_at' => $nextRunAt,
            'is_active'   => 1,
        ]);
        return $this->find($customerId, $id);
    }

    public function update(int $customerId, int $id, array $data): array
    {
        $row = Db::one('SELECT * FROM recurring_orders WHERE id = ? AND customer_id = ?', [$id, $customerId]);
        if ($row === null) {
            throw HttpException::notFound('Schedule not found.');
        }
        $input = Validator::validate($data, [
            'quantity'    => 'nullable|integer',
            'frequency_id'=> 'nullable|integer|exists:frequencies,id',
            'next_run_at' => 'nullable|string',
            'is_active'   => 'nullable|bool',
        ]);
        if (isset($input['quantity'])) {
            $this->pricing->assertQuantity((int) $input['quantity']);
        }
        $update = [];
        if (isset($input['quantity'])) {
            $update['quantity'] = (int) $input['quantity'];
        }
        if (isset($input['frequency_id'])) {
            $update['frequency_id'] = (int) $input['frequency_id'];
        }
        if (isset($input['is_active'])) {
            $update['is_active'] = !empty($input['is_active']) ? 1 : 0;
        }
        if (isset($input['next_run_at']) && strtotime((string) $input['next_run_at']) !== false) {
            $update['next_run_at'] = gmdate('Y-m-d H:i:s', strtotime((string) $input['next_run_at']));
        }
        if ($update !== []) {
            Db::update('recurring_orders', $update, 'id = ?', [$id]);
        }
        return $this->find($customerId, $id);
    }

    public function delete(int $customerId, int $id): void
    {
        $row = Db::one('SELECT id FROM recurring_orders WHERE id = ? AND customer_id = ?', [$id, $customerId]);
        if ($row === null) {
            throw HttpException::notFound('Schedule not found.');
        }
        Db::run('DELETE FROM recurring_orders WHERE id = ?', [$id]);
    }

    public function find(int $customerId, int $id): array
    {
        $row = Db::one(
            'SELECT r.*, f.name AS frequency, v.business_name, l.alias AS location_alias
             FROM recurring_orders r
             JOIN frequencies f ON f.id = r.frequency_id
             JOIN vendors v ON v.id = r.vendor_id
             JOIN locations l ON l.id = r.location_id
             WHERE r.id = ? AND r.customer_id = ?',
            [$id, $customerId]
        );
        if ($row === null) {
            throw HttpException::notFound('Schedule not found.');
        }
        // TINYINT arrives as a number; the Android client types is_active as boolean.
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    /** Cron: turn due schedules into pending orders. */
    public function runDue(): int
    {
        $orders = new OrderService();
        $due = Db::all(
            'SELECT r.*, f.interval_days, v.is_online, v.user_id AS vendor_user_id
             FROM recurring_orders r
             JOIN frequencies f ON f.id = r.frequency_id
             JOIN vendors v ON v.id = r.vendor_id
             WHERE r.is_active = 1 AND r.next_run_at <= UTC_TIMESTAMP()'
        );
        $created = 0;
        foreach ($due as $row) {
            try {
                $orders->create((int) $row['customer_id'], [
                    'vendor_id'   => (int) $row['vendor_id'],
                    'location_id' => (int) $row['location_id'],
                    'quantity'    => (int) $row['quantity'],
                    'note'        => 'Scheduled repeat delivery',
                    'payment_method' => 'Cash',
                ]);
                $created++;
            } catch (\Throwable $e) {
                // Vendor offline or out of area: tell the customer and offer alternatives.
                $this->notifications->send(
                    (int) $row['customer_id'],
                    'Scheduled delivery needs a vendor',
                    'Your repeat delivery could not be placed. Choose another vendor for today.',
                    ['type' => 'recurring_failed']
                );
            }
            Db::update('recurring_orders', [
                'next_run_at' => gmdate('Y-m-d H:i:s', time() + (int) $row['interval_days'] * 86400),
            ], 'id = ?', [$row['id']]);
        }
        return $created;
    }
}
