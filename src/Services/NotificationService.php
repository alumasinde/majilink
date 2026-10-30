<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Providers\Push\Fcm;
use MajiLink\Support\Db;

final class NotificationService
{
    /**
     * Stores the notification and pushes it to every active device with an FCM token.
     */
    public function send(int $userId, string $title, string $body, array $data = [], string $channel = 'majilink_default'): int
    {
        $id = Db::insert('notifications', [
            'user_id'   => $userId,
            'title'     => mb_substr($title, 0, 120),
            'body'      => mb_substr($body, 0, 500),
            'data_json' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $tokens = Db::all(
            'SELECT fcm_token FROM user_devices WHERE user_id = ? AND revoked_at IS NULL AND fcm_token IS NOT NULL',
            [$userId]
        );
        foreach ($tokens as $token) {
            Fcm::send((string) $token['fcm_token'], $title, $body, $data, $channel);
        }
        return $id;
    }

    /** Fire-and-forget push for vendors on a dedicated loud channel. */
    public function notifyVendorUser(int $vendorUserId, string $title, string $body, array $data = []): void
    {
        $this->send($vendorUserId, $title, $body, $data, 'majilink_orders');
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        return Db::all(
            'SELECT id, title, body, data_json, read_at, created_at
             FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . (int) $limit,
            [$userId]
        );
    }

    public function markRead(int $userId, int $notificationId): void
    {
        Db::run('UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE id = ? AND user_id = ?', [$notificationId, $userId]);
    }

    public function broadcast(array $roleNames, string $title, string $body): int
    {
        $rows = Db::all(
            'SELECT DISTINCT u.id
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.is_active = 1 AND r.name IN (' . implode(',', array_fill(0, count($roleNames), '?')) . ')',
            array_values($roleNames)
        );
        foreach ($rows as $row) {
            $this->send((int) $row['id'], $title, $body, ['type' => 'broadcast']);
        }
        return count($rows);
    }
}
