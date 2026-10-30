<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Support\Db;

final class ReportService
{
    public function dashboard(): array
    {
        $deliveredOnly = "JOIN order_statuses s ON s.id = o.status_id AND s.name = 'Delivered'";

        $ordersToday = (int) Db::value('SELECT COUNT(*) FROM orders WHERE created_at >= UTC_DATE()');
        $vendorsOnline = (int) Db::value(
            'SELECT COUNT(*) FROM vendors v JOIN vendor_application_statuses s ON s.id = v.application_status_id
             WHERE s.name = ? AND v.is_online = 1',
            ['Approved']
        );
        $newCustomers = (int) Db::value(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'customer' AND u.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
        $revenue = Db::one(
            "SELECT COALESCE(SUM(o.total), 0) AS gross, COALESCE(SUM(o.commission_amount), 0) AS commission
             FROM orders o $deliveredOnly WHERE o.delivered_at >= UTC_DATE()"
        );
        $pendingApplications = (int) Db::value(
            'SELECT COUNT(*) FROM vendors v JOIN vendor_application_statuses s ON s.id = v.application_status_id WHERE s.name = ?',
            ['Pending']
        );
        $failedPayments = (int) Db::value(
            'SELECT COUNT(*) FROM payments p JOIN payment_statuses s ON s.id = p.status_id WHERE s.name = ?',
            ['Failed']
        );
        $pendingPayouts = (int) Db::value(
            'SELECT COUNT(*) FROM payouts p JOIN payout_statuses s ON s.id = p.status_id WHERE s.name = ?',
            ['Requested']
        );

        $series = Db::all(
            "SELECT DATE(o.created_at) AS day,
                    COUNT(*) AS orders,
                    COALESCE(SUM(CASE WHEN s.name = 'Delivered' THEN o.total ELSE 0 END), 0) AS gross
             FROM orders o JOIN order_statuses s ON s.id = o.status_id
             WHERE o.created_at >= DATE_SUB(UTC_DATE(), INTERVAL 13 DAY)
             GROUP BY DATE(o.created_at) ORDER BY day"
        );
        $byDay = [];
        foreach ($series as $row) {
            $byDay[$row['day']] = $row;
        }
        $chart = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-{$i} day"));
            $chart[] = [
                'day'    => $day,
                'orders' => (int) ($byDay[$day]['orders'] ?? 0),
                'gross'  => (float) ($byDay[$day]['gross'] ?? 0),
            ];
        }

        $recent = Db::all(
            'SELECT o.id, o.order_no, o.total, o.created_at, s.name AS status,
                    CONCAT(u.first_name, " ", u.last_name) AS customer, v.business_name
             FROM orders o
             JOIN order_statuses s ON s.id = o.status_id
             JOIN users u ON u.id = o.customer_id
             JOIN vendors v ON v.id = o.vendor_id
             ORDER BY o.id DESC LIMIT 8'
        );

        return [
            'cards' => [
                'orders_today'         => $ordersToday,
                'vendors_online'       => $vendorsOnline,
                'new_customers_7d'     => $newCustomers,
                'revenue_today'        => (string) $revenue['gross'],
                'commission_today'     => (string) $revenue['commission'],
                'pending_applications' => $pendingApplications,
                'failed_payments'      => $failedPayments,
                'pending_payouts'      => $pendingPayouts,
            ],
            'chart'  => $chart,
            'recent' => $recent,
        ];
    }

    public function ordersByDay(string $from, string $to): array
    {
        return Db::all(
            'SELECT DATE(o.created_at) AS day, COUNT(*) AS orders,
                    SUM(CASE WHEN s.name = "Delivered" THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN s.name IN ("Declined", "Cancelled", "Expired") THEN 1 ELSE 0 END) AS lost,
                    COALESCE(SUM(CASE WHEN s.name = "Delivered" THEN o.total ELSE 0 END), 0) AS gross,
                    COALESCE(SUM(CASE WHEN s.name = "Delivered" THEN o.commission_amount ELSE 0 END), 0) AS commission
             FROM orders o JOIN order_statuses s ON s.id = o.status_id
             WHERE o.created_at BETWEEN ? AND ?
             GROUP BY DATE(o.created_at) ORDER BY day',
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
    }

    public function ordersByArea(string $from, string $to): array
    {
        return Db::all(
            'SELECT o.delivery_alias AS area, COUNT(*) AS orders,
                    COALESCE(SUM(CASE WHEN s.name = "Delivered" THEN o.total ELSE 0 END), 0) AS gross
             FROM orders o JOIN order_statuses s ON s.id = o.status_id
             WHERE o.created_at BETWEEN ? AND ?
             GROUP BY o.delivery_alias ORDER BY orders DESC LIMIT 50',
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
    }

    public function vendorPerformance(string $from, string $to): array
    {
        $rows = Db::all(
            'SELECT v.id, v.business_name, ws.name AS water_source, v.avg_rating, v.rating_count,
                    COUNT(o.id) AS orders,
                    SUM(CASE WHEN s.name = "Delivered" THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN s.name = "Declined" THEN 1 ELSE 0 END) AS declined,
                    SUM(CASE WHEN s.name IN ("Cancelled", "Expired") THEN 1 ELSE 0 END) AS dropped,
                    COALESCE(SUM(CASE WHEN s.name = "Delivered" THEN o.total ELSE 0 END), 0) AS gross,
                    COALESCE(SUM(CASE WHEN s.name = "Delivered" THEN o.commission_amount ELSE 0 END), 0) AS commission
             FROM vendors v
             LEFT JOIN orders o ON o.vendor_id = v.id AND o.created_at BETWEEN ? AND ?
             LEFT JOIN order_statuses s ON s.id = o.status_id
             JOIN water_sources ws ON ws.id = v.water_source_id
             GROUP BY v.id, v.business_name, ws.name, v.avg_rating, v.rating_count
             ORDER BY orders DESC',
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        foreach ($rows as &$row) {
            $handled = (int) $row['delivered'] + (int) $row['declined'] + (int) $row['dropped'];
            $row['reliability_percent'] = $handled === 0 ? null : round((int) $row['delivered'] / $handled * 100, 1);
        }
        return $rows;
    }

    public function revenueSummary(string $from, string $to): array
    {
        $row = Db::one(
            'SELECT COUNT(*) AS orders,
                    COALESCE(SUM(o.total), 0) AS gross,
                    COALESCE(SUM(o.commission_amount), 0) AS commission
             FROM orders o JOIN order_statuses s ON s.id = o.status_id
             WHERE s.name = "Delivered" AND o.delivered_at BETWEEN ? AND ?',
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        $byMethod = Db::all(
            'SELECT pm.name AS method, COUNT(*) AS payments, COALESCE(SUM(p.amount), 0) AS amount
             FROM payments p
             JOIN payment_methods pm ON pm.id = p.method_id
             JOIN payment_statuses ps ON ps.id = p.status_id
             WHERE ps.name = "Paid" AND p.created_at BETWEEN ? AND ?
             GROUP BY pm.name',
            [$from . ' 00:00:00', $to . ' 23:59:59']
        );
        return ['summary' => $row, 'by_method' => $byMethod];
    }

    public function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row));
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $csv;
    }
}
