<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Providers\Maps\RoutesProvider;
use MajiLink\Support\Db;
use MajiLink\Support\Geo;
use MajiLink\Support\Validator;

final class VendorService
{
    public function __construct(
        private readonly SettingsService $settings = new SettingsService(),
        private readonly RoutesProvider $routes = new RoutesProvider()
    ) {
    }

    /**
     * Approved, online vendors inside the customer radius, sorted by distance.
     * The first ten results also carry road distance and ETA.
     */
    public function nearby(float $lat, float $lng, ?float $radiusKm = null): array
    {
        $default = $this->settings->float('default_radius_km', 3);
        $max = $this->settings->float('max_radius_km', 5);
        $radius = $radiusKm === null ? $default : $radiusKm;
        if ($radius <= 0) {
            $radius = $default;
        }
        $radius = min($radius, $max);
        $box = Geo::boundingBox($lat, $lng, $radius);
        $localTime = $this->settings->nowLocalTime();

        $rows = Db::all(
            'SELECT * FROM (
                SELECT v.id, v.business_name, v.price_per_20l, v.delivery_radius_km,
                       v.base_lat, v.base_lng, v.opens_at, v.closes_at, v.avg_rating, v.rating_count,
                       ws.name AS water_source, ws.id AS water_source_id,
                       (6371.0088 * 2 * ASIN(SQRT(
                            POWER(SIN(RADIANS(v.base_lat - ?) / 2), 2) +
                            COS(RADIANS(?)) * COS(RADIANS(v.base_lat)) *
                            POWER(SIN(RADIANS(v.base_lng - ?) / 2), 2)
                       ))) AS distance_km
                FROM vendors v
                JOIN water_sources ws ON ws.id = v.water_source_id
                JOIN vendor_application_statuses s ON s.id = v.application_status_id
                WHERE s.name = ?
                  AND v.is_online = 1
                  AND v.last_heartbeat_at >= ?
                  AND v.base_lat BETWEEN ? AND ?
                  AND v.base_lng BETWEEN ? AND ?
             ) nearby
             WHERE nearby.distance_km <= LEAST(?, nearby.delivery_radius_km)
             ORDER BY nearby.distance_km ASC, nearby.price_per_20l ASC
             LIMIT 50',
            [
                $lat, $lat, $lng,
                'Approved',
                $this->settings->heartbeatCutoff(),
                $box['min_lat'], $box['max_lat'], $box['min_lng'], $box['max_lng'],
                $radius,
            ]
        );

        $vendors = [];
        foreach ($rows as $row) {
            if (!$this->withinHours($row, $localTime)) {
                continue;
            }
            $vendor = [
                'id'              => (int) $row['id'],
                'business_name'   => $row['business_name'],
                'water_source'    => ['id' => (int) $row['water_source_id'], 'name' => $row['water_source']],
                'price_per_20l'   => $row['price_per_20l'],
                'avg_rating'      => (float) $row['avg_rating'],
                'rating_count'    => (int) $row['rating_count'],
                'distance_km'     => round((float) $row['distance_km'], 2),
                'opens_at'        => $row['opens_at'],
                'closes_at'       => $row['closes_at'],
                'base_lat'        => (float) $row['base_lat'],
                'base_lng'        => (float) $row['base_lng'],
                'eta_minutes'     => null,
                'road_distance_km'=> null,
            ];
            $vendors[] = $vendor;
        }

        foreach ($vendors as $index => $vendor) {
            if ($index >= 10) {
                break;
            }
            $route = $this->routes->estimate((float) $vendor['base_lat'], (float) $vendor['base_lng'], $lat, $lng);
            $vendors[$index]['eta_minutes'] = $route['duration_min'];
            $vendors[$index]['road_distance_km'] = $route['distance_km'];
        }

        return ['radius_km' => $radius, 'max_radius_km' => $max, 'vendors' => $vendors];
    }

    public function show(int $vendorId): array
    {
        $row = Db::one(
            'SELECT v.*, ws.name AS water_source, s.name AS status
             FROM vendors v
             JOIN water_sources ws ON ws.id = v.water_source_id
             JOIN vendor_application_statuses s ON s.id = v.application_status_id
             WHERE v.id = ?',
            [$vendorId]
        );
        if ($row === null || $row['status'] !== 'Approved') {
            throw HttpException::notFound('Vendor not found.');
        }
        return [
            'id'              => (int) $row['id'],
            'business_name'   => $row['business_name'],
            'water_source'    => $row['water_source'],
            'price_per_20l'   => $row['price_per_20l'],
            'avg_rating'      => (float) $row['avg_rating'],
            'rating_count'    => (int) $row['rating_count'],
            'opens_at'        => $row['opens_at'],
            'closes_at'       => $row['closes_at'],
            'is_online'       => (bool) $row['is_online'],
            'base_lat'        => (float) $row['base_lat'],
            'base_lng'        => (float) $row['base_lng'],
            'base_note'       => $row['base_note'],
        ];
    }

    public function application(int $userId): ?array
    {
        $row = Db::one(
            'SELECT v.*, s.name AS status, ws.name AS water_source
             FROM vendors v
             JOIN vendor_application_statuses s ON s.id = v.application_status_id
             JOIN water_sources ws ON ws.id = v.water_source_id
             WHERE v.user_id = ?',
            [$userId]
        );
        if ($row === null) {
            return null;
        }
        $documents = Db::all(
            'SELECT d.id, d.document_type_id, t.name AS document_type, d.uploaded_at
             FROM vendor_documents d JOIN document_types t ON t.id = d.document_type_id
             WHERE d.vendor_id = ? ORDER BY d.id',
            [$row['id']]
        );
        return [
            'id'                 => (int) $row['id'],
            'business_name'      => $row['business_name'],
            'water_source'       => $row['water_source'],
            'water_source_id'    => (int) $row['water_source_id'],
            'price_per_20l'      => $row['price_per_20l'],
            'delivery_radius_km' => $row['delivery_radius_km'],
            'base_lat'           => (float) $row['base_lat'],
            'base_lng'           => (float) $row['base_lng'],
            'base_note'          => $row['base_note'],
            'opens_at'           => $row['opens_at'],
            'closes_at'          => $row['closes_at'],
            'payout_phone'       => $row['payout_phone'],
            'status'             => $row['status'],
            'rejection_reason'   => $row['rejection_reason'],
            'reviewed_at'        => $row['reviewed_at'],
            'is_online'          => (bool) $row['is_online'],
            'documents'          => $documents,
        ];
    }

    public function apply(int $userId, array $data): array
    {
        $input = Validator::validate($data, [
            'business_name'      => 'required|string|min:3|max:120',
            'water_source_id'    => 'required|integer|exists:water_sources,id',
            'price_per_20l'      => 'required|numeric|min:1|max:10000',
            'delivery_radius_km' => 'required|numeric|min:0.5|max:' . $this->settings->float('max_radius_km', 5),
            'base_lat'           => 'required|lat',
            'base_lng'           => 'required|lng',
            'base_note'          => 'nullable|string|max:255',
            'opens_at'           => 'nullable|time',
            'closes_at'          => 'nullable|time',
            'payout_phone'       => 'required|phone',
        ]);

        $existing = Db::one('SELECT * FROM vendors WHERE user_id = ?', [$userId]);
        if ($existing !== null) {
            $status = (string) Db::value(
                'SELECT s.name FROM vendors v JOIN vendor_application_statuses s ON s.id = v.application_status_id WHERE v.id = ?',
                [$existing['id']]
            );
            if (in_array($status, ['Pending', 'Approved'], true)) {
                throw HttpException::conflict('You already have a ' . strtolower($status) . ' application.');
            }
        }

        $payload = [
            'business_name'         => $input['business_name'],
            'water_source_id'       => (int) $input['water_source_id'],
            'price_per_20l'         => $input['price_per_20l'],
            'delivery_radius_km'    => $input['delivery_radius_km'],
            'base_lat'              => $input['base_lat'],
            'base_lng'              => $input['base_lng'],
            'base_note'             => $input['base_note'] ?? null,
            'opens_at'              => $input['opens_at'] ?? null,
            'closes_at'             => $input['closes_at'] ?? null,
            'payout_phone'          => $input['payout_phone'],
            'application_status_id' => $this->statusId('Pending'),
            'is_online'             => 0,
            'reviewed_by'           => null,
            'reviewed_at'           => null,
            'rejection_reason'      => null,
        ];

        if ($existing === null) {
            $payload['user_id'] = $userId;
            $vendorId = Db::insert('vendors', $payload);
            Db::insert('vendor_wallets', ['vendor_id' => $vendorId, 'balance' => '0.00']);
        } else {
            $vendorId = (int) $existing['id'];
            Db::update('vendors', $payload, 'id = ?', [$vendorId]);
        }

        (new AuditService())->record($userId, 'vendors.apply', 'vendors', $vendorId);
        return $this->application($userId) ?? [];
    }

    public function addDocument(int $userId, int $documentTypeId, array $file): array
    {
        $vendor = Db::one('SELECT * FROM vendors WHERE user_id = ?', [$userId]);
        if ($vendor === null) {
            throw HttpException::badRequest('Submit a vendor application before uploading documents.');
        }
        if (Db::value('SELECT 1 FROM document_types WHERE id = ?', [$documentTypeId]) === null) {
            throw HttpException::validation(['document_type_id' => 'Unknown document type.']);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw HttpException::validation(['file' => 'Upload an image or PDF up to 8 MB.']);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!isset($allowed[$mime])) {
            throw HttpException::validation(['file' => 'Only JPG, PNG, WebP or PDF files are accepted.']);
        }

        $dir = (string) \MajiLink\Support\Config::get('upload_dir') . '/vendor-docs/' . $vendor['id'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the upload directory.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new \RuntimeException('Unable to store the uploaded file.');
        }
        $relative = 'vendor-docs/' . $vendor['id'] . '/' . $name;

        $id = Db::insert('vendor_documents', [
            'vendor_id'        => $vendor['id'],
            'document_type_id' => $documentTypeId,
            'file_path'        => $relative,
        ]);
        (new AuditService())->record($userId, 'vendors.document.upload', 'vendor_documents', $id, ['vendor_id' => $vendor['id']]);
        return ['id' => $id, 'document_type_id' => $documentTypeId, 'uploaded_at' => gmdate('Y-m-d H:i:s')];
    }

    public function documentForAdmin(int $documentId): array
    {
        $row = Db::one(
            'SELECT d.*, v.business_name, t.name AS document_type
             FROM vendor_documents d
             JOIN vendors v ON v.id = d.vendor_id
             JOIN document_types t ON t.id = d.document_type_id
             WHERE d.id = ?',
            [$documentId]
        );
        if ($row === null) {
            throw HttpException::notFound('Document not found.');
        }
        return $row;
    }

    public function updateProfile(int $userId, array $data): array
    {
        $vendor = Db::one('SELECT * FROM vendors WHERE user_id = ?', [$userId]);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor profile not found.');
        }
        $input = Validator::validate($data, [
            'business_name'      => 'nullable|string|min:3|max:120',
            'water_source_id'    => 'nullable|integer|exists:water_sources,id',
            'price_per_20l'      => 'nullable|numeric|min:1|max:10000',
            'delivery_radius_km' => 'nullable|numeric|min:0.5|max:' . $this->settings->float('max_radius_km', 5),
            'opens_at'           => 'nullable|time',
            'closes_at'          => 'nullable|time',
            'payout_phone'       => 'nullable|phone',
            'base_lat'           => 'nullable|lat',
            'base_lng'           => 'nullable|lng',
            'base_note'          => 'nullable|string|max:255',
        ]);

        $pinMoved = (isset($input['base_lat']) && abs((float) $input['base_lat'] - (float) $vendor['base_lat']) > 0.00001)
            || (isset($input['base_lng']) && abs((float) $input['base_lng'] - (float) $vendor['base_lng']) > 0.00001);

        $update = array_intersect_key($input, array_flip([
            'business_name', 'water_source_id', 'price_per_20l', 'delivery_radius_km',
            'opens_at', 'closes_at', 'payout_phone', 'base_lat', 'base_lng', 'base_note',
        ]));

        if ($pinMoved) {
            // Moving the shop pin requires a fresh review; price and hours do not.
            $update['application_status_id'] = $this->statusId('Pending');
            $update['is_online'] = 0;
            $update['reviewed_by'] = null;
            $update['reviewed_at'] = null;
            $update['rejection_reason'] = null;
        }

        if ($update !== []) {
            Db::update('vendors', $update, 'id = ?', [$vendor['id']]);
        }
        (new AuditService())->record($userId, 'vendors.profile.update', 'vendors', (int) $vendor['id'], ['reapproval' => $pinMoved]);

        return $this->application($userId) ?? [];
    }

    public function setOnline(int $userId, bool $online): array
    {
        $vendor = $this->requireApprovedVendor($userId);
        Db::update('vendors', [
            'is_online'         => $online ? 1 : 0,
            'last_heartbeat_at' => $online ? gmdate('Y-m-d H:i:s') : $vendor['last_heartbeat_at'],
        ], 'id = ?', [$vendor['id']]);
        return ['is_online' => $online, 'last_heartbeat_at' => $online ? gmdate('Y-m-d H:i:s') : $vendor['last_heartbeat_at']];
    }

    public function heartbeat(int $userId): array
    {
        $vendor = $this->requireApprovedVendor($userId);
        Db::update('vendors', ['last_heartbeat_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$vendor['id']]);
        return ['ok' => true, 'server_time' => gmdate('Y-m-d H:i:s')];
    }

    public function requireApprovedVendor(int $userId): array
    {
        $vendor = Db::one(
            'SELECT v.*, s.name AS status FROM vendors v
             JOIN vendor_application_statuses s ON s.id = v.application_status_id
             WHERE v.user_id = ?',
            [$userId]
        );
        if ($vendor === null) {
            throw HttpException::forbidden('You do not have a vendor account.');
        }
        if ($vendor['status'] !== 'Approved') {
            throw new HttpException(403, 'vendor_not_approved', 'Your vendor application is ' . strtolower($vendor['status']) . '.');
        }
        return $vendor;
    }

    public function review(int $vendorId, int $adminId, string $decision, ?string $reason = null, ?string $ip = null): array
    {
        $vendor = Db::one(
            'SELECT v.*, s.name AS status, u.id AS vendor_user_id
             FROM vendors v
             JOIN vendor_application_statuses s ON s.id = v.application_status_id
             JOIN users u ON u.id = v.user_id
             WHERE v.id = ?',
            [$vendorId]
        );
        if ($vendor === null) {
            throw HttpException::notFound('Vendor application not found.');
        }
        if ($vendor['status'] !== 'Pending') {
            throw HttpException::conflict('Only pending applications can be reviewed.');
        }
        if ($decision === 'approve') {
            Db::transaction(function () use ($vendor, $adminId): void {
                Db::update('vendors', [
                    'application_status_id' => $this->statusId('Approved'),
                    'reviewed_by'           => $adminId,
                    'reviewed_at'           => gmdate('Y-m-d H:i:s'),
                    'rejection_reason'      => null,
                ], 'id = ?', [$vendor['id']]);
                $roleId = (int) Db::value('SELECT id FROM roles WHERE name = ?', ['vendor']);
                Db::run('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)', [$vendor['vendor_user_id'], $roleId]);
            });
            (new NotificationService())->notifyVendorUser(
                (int) $vendor['vendor_user_id'],
                'Application approved',
                'Your shop is approved. Go online to start receiving orders.',
                ['type' => 'vendor_approved', 'vendor_id' => (string) $vendor['id']]
            );
        } elseif ($decision === 'reject') {
            if ($reason === null || trim($reason) === '') {
                throw HttpException::validation(['reason' => 'Tell the vendor why the application was rejected.']);
            }
            Db::update('vendors', [
                'application_status_id' => $this->statusId('Rejected'),
                'reviewed_by'           => $adminId,
                'reviewed_at'           => gmdate('Y-m-d H:i:s'),
                'rejection_reason'      => mb_substr(trim($reason), 0, 255),
                'is_online'             => 0,
            ], 'id = ?', [$vendor['id']]);
            (new NotificationService())->notifyVendorUser(
                (int) $vendor['vendor_user_id'],
                'Application needs changes',
                'Reason: ' . $reason,
                ['type' => 'vendor_rejected', 'vendor_id' => (string) $vendor['id']]
            );
        } else {
            throw HttpException::badRequest('Decision must be approve or reject.');
        }

        (new AuditService())->record($adminId, 'vendors.review.' . $decision, 'vendors', $vendorId, ['reason' => $reason], $ip);
        return ['id' => $vendorId, 'status' => $decision === 'approve' ? 'Approved' : 'Rejected'];
    }

    public function setSuspended(int $vendorId, int $adminId, bool $suspend, ?string $reason = null, ?string $ip = null): array
    {
        $vendor = Db::one(
            'SELECT v.*, s.name AS status FROM vendors v
             JOIN vendor_application_statuses s ON s.id = v.application_status_id WHERE v.id = ?',
            [$vendorId]
        );
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }
        if ($suspend) {
            if ($vendor['status'] !== 'Approved') {
                throw HttpException::conflict('Only approved vendors can be suspended.');
            }
            Db::update('vendors', [
                'application_status_id' => $this->statusId('Suspended'),
                'is_online'             => 0,
                'rejection_reason'      => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            ], 'id = ?', [$vendorId]);
            (new NotificationService())->notifyVendorUser((int) $vendor['user_id'], 'Shop suspended', $reason ?: 'Contact support for details.', ['type' => 'vendor_suspended']);
        } else {
            if ($vendor['status'] !== 'Suspended') {
                throw HttpException::conflict('Only suspended vendors can be reactivated.');
            }
            Db::update('vendors', [
                'application_status_id' => $this->statusId('Approved'),
                'rejection_reason'      => null,
            ], 'id = ?', [$vendorId]);
            (new NotificationService())->send((int) $vendor['user_id'], 'Shop reactivated', 'Your shop is approved again. Go online to receive orders.', ['type' => 'vendor_reactivated']);
        }
        (new AuditService())->record($adminId, $suspend ? 'vendors.suspend' : 'vendors.reactivate', 'vendors', $vendorId, ['reason' => $reason], $ip);
        return ['id' => $vendorId, 'suspended' => $suspend];
    }

    public function listForAdmin(?string $status = null, ?string $source = null, ?string $search = null): array
    {
        $sql = 'SELECT v.id, v.business_name, v.price_per_20l, v.is_online, v.avg_rating, v.rating_count,
                       v.created_at, s.name AS status, ws.name AS water_source,
                       CONCAT(u.first_name, " ", u.last_name) AS owner_name, u.phone AS owner_phone,
                       w.balance
                FROM vendors v
                JOIN vendor_application_statuses s ON s.id = v.application_status_id
                JOIN water_sources ws ON ws.id = v.water_source_id
                JOIN users u ON u.id = v.user_id
                LEFT JOIN vendor_wallets w ON w.vendor_id = v.id
                WHERE 1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND s.name = ?';
            $params[] = $status;
        }
        if ($source !== null && $source !== '') {
            $sql .= ' AND ws.name = ?';
            $params[] = $source;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (v.business_name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        $sql .= ' ORDER BY FIELD(s.name, "Pending", "Approved", "Suspended", "Rejected"), v.created_at DESC';
        return Db::all($sql, $params);
    }

    public function statusId(string $name): int
    {
        $id = Db::value('SELECT id FROM vendor_application_statuses WHERE name = ?', [$name]);
        if ($id === null) {
            throw new \RuntimeException('Unknown vendor status: ' . $name);
        }
        return (int) $id;
    }

    private function withinHours(array $vendor, string $localTime): bool
    {
        if ($vendor['opens_at'] === null || $vendor['closes_at'] === null) {
            return true;
        }
        $open = (string) $vendor['opens_at'];
        $close = (string) $vendor['closes_at'];
        if ($open <= $close) {
            return $localTime >= $open && $localTime <= $close;
        }
        // Window that crosses midnight, e.g. 20:00 to 02:00.
        return $localTime >= $open || $localTime <= $close;
    }
}
