<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Validator;

final class RatingService
{
    public function create(int $customerId, int $orderId, array $data): array
    {
        $input = Validator::validate($data, [
            'stars'   => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $order = Db::one(
            'SELECT o.*, s.name AS status FROM orders o JOIN order_statuses s ON s.id = o.status_id WHERE o.id = ?',
            [$orderId]
        );
        if ($order === null || (int) $order['customer_id'] !== $customerId) {
            throw HttpException::notFound('Order not found.');
        }
        if ($order['status'] !== 'Delivered') {
            throw HttpException::conflict('You can rate an order once it is delivered.');
        }
        $existing = Db::value('SELECT id FROM ratings WHERE order_id = ?', [$orderId]);
        if ($existing !== null) {
            throw HttpException::conflict('This order already has a rating.');
        }

        $id = Db::transaction(function () use ($order, $customerId, $input): int {
            $ratingId = Db::insert('ratings', [
                'order_id'    => $order['id'],
                'customer_id' => $customerId,
                'vendor_id'   => $order['vendor_id'],
                'stars'       => (int) $input['stars'],
                'comment'     => $input['comment'] ?? null,
            ]);

            $stats = Db::one(
                'SELECT AVG(stars) AS avg_stars, COUNT(*) AS total FROM ratings WHERE vendor_id = ?',
                [$order['vendor_id']]
            );
            Db::update('vendors', [
                'avg_rating'   => number_format((float) ($stats['avg_stars'] ?? 0), 2, '.', ''),
                'rating_count' => (int) ($stats['total'] ?? 0),
            ], 'id = ?', [$order['vendor_id']]);
            return $ratingId;
        });

        return ['id' => $id, 'stars' => (int) $input['stars'], 'comment' => $input['comment'] ?? null];
    }

    public function forVendor(int $vendorId): array
    {
        return Db::all(
            'SELECT r.id, r.stars, r.comment, r.created_at, o.order_no,
                    CONCAT(u.first_name, " ", u.last_name) AS customer_name
             FROM ratings r
             JOIN orders o ON o.id = r.order_id
             JOIN users u ON u.id = r.customer_id
             WHERE r.vendor_id = ? ORDER BY r.id DESC LIMIT 50',
            [$vendorId]
        );
    }
}
