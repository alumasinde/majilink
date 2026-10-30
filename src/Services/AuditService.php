<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Support\Db;

final class AuditService
{
    public function record(?int $userId, string $action, string $entity, ?int $entityId, array $details = [], ?string $ip = null): void
    {
        Db::insert('audit_logs', [
            'user_id'     => $userId,
            'action'      => $action,
            'entity'      => $entity,
            'entity_id'   => $entityId,
            'ip'          => $ip,
            'details_json'=> $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function recent(int $limit = 100, ?string $search = null): array
    {
        if ($search !== null && $search !== '') {
            return Db::all(
                'SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS staff_name
                 FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.action LIKE ? OR a.entity LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?
                 ORDER BY a.created_at DESC LIMIT ' . (int) $limit,
                array_fill(0, 4, '%' . $search . '%')
            );
        }
        return Db::all(
            'SELECT a.*, CONCAT(u.first_name, " ", u.last_name) AS staff_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC LIMIT ' . (int) $limit
        );
    }
}
