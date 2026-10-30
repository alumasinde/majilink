<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Support\Db;
use MajiLink\Support\Money;

final class WalletService
{
    public function balanceCents(int $vendorId): int
    {
        $balance = Db::value('SELECT balance FROM vendor_wallets WHERE vendor_id = ?', [$vendorId]);
        return Money::cents($balance ?? 0);
    }

    public function credit(int $vendorId, string $typeName, ?int $orderId, int $amountCents, string $note): void
    {
        $this->apply($vendorId, $typeName, $orderId, abs($amountCents), $note);
    }

    public function debit(int $vendorId, string $typeName, ?int $orderId, int $amountCents, string $note): void
    {
        $this->apply($vendorId, $typeName, $orderId, -abs($amountCents), $note);
    }

    /** Guards against double-crediting an order (e.g. a duplicated M-Pesa callback). */
    public function hasEntry(int $vendorId, int $orderId, string $typeName): bool
    {
        return Db::value(
            'SELECT 1 FROM wallet_transactions w
             JOIN transaction_types t ON t.id = w.type_id
             WHERE w.vendor_id = ? AND w.order_id = ? AND t.name = ? LIMIT 1',
            [$vendorId, $orderId, $typeName]
        ) !== null;
    }

    public function summary(int $vendorId): array
    {
        $balance = $this->balanceCents($vendorId);
        $pending = Money::cents(Db::value(
            "SELECT COALESCE(SUM(p.amount), 0) FROM payouts p
             JOIN payout_statuses s ON s.id = p.status_id
             WHERE p.vendor_id = ? AND s.name IN ('Requested', 'Approved')",
            [$vendorId]
        ));

        $earnedToday = Money::cents(Db::value(
            "SELECT COALESCE(SUM(w.amount), 0) FROM wallet_transactions w
             JOIN transaction_types t ON t.id = w.type_id
             WHERE w.vendor_id = ? AND t.name = 'Order earning' AND DATE(w.created_at) = UTC_DATE()",
            [$vendorId]
        ));
        $earnedWeek = Money::cents(Db::value(
            "SELECT COALESCE(SUM(w.amount), 0) FROM wallet_transactions w
             JOIN transaction_types t ON t.id = w.type_id
             WHERE w.vendor_id = ? AND t.name = 'Order earning' AND w.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
            [$vendorId]
        ));

        return [
            'balance'         => Money::decimal($balance),
            'pending_payouts' => Money::decimal($pending),
            'available'       => Money::decimal(max(0, $balance - $pending)),
            'earned_today'    => Money::decimal($earnedToday),
            'earned_week'     => Money::decimal($earnedWeek),
        ];
    }

    public function transactions(int $vendorId, int $limit = 100): array
    {
        $rows = Db::all(
            'SELECT w.id, t.name AS type, w.amount, w.balance_after, w.note, w.order_id, w.created_at
             FROM wallet_transactions w
             JOIN transaction_types t ON t.id = w.type_id
             WHERE w.vendor_id = ?
             ORDER BY w.id DESC LIMIT ' . (int) $limit,
            [$vendorId]
        );
        foreach ($rows as &$row) {
            $row['amount_display'] = Money::display(Money::cents($row['amount']));
        }
        return $rows;
    }

    private function apply(int $vendorId, string $typeName, ?int $orderId, int $deltaCents, string $note): void
    {
        $typeId = Db::value('SELECT id FROM transaction_types WHERE name = ?', [$typeName]);
        if ($typeId === null) {
            throw new \RuntimeException('Unknown transaction type: ' . $typeName);
        }

        Db::transaction(function () use ($vendorId, $typeId, $orderId, $deltaCents, $note): void {
            Db::run('INSERT IGNORE INTO vendor_wallets (vendor_id, balance) VALUES (?, 0)', [$vendorId]);
            $row = Db::one('SELECT balance FROM vendor_wallets WHERE vendor_id = ? FOR UPDATE', [$vendorId]);
            $balance = Money::cents($row['balance'] ?? 0) + $deltaCents;

            Db::update('vendor_wallets', ['balance' => Money::decimal($balance)], 'vendor_id = ?', [$vendorId]);
            Db::insert('wallet_transactions', [
                'vendor_id'     => $vendorId,
                'type_id'       => $typeId,
                'order_id'      => $orderId,
                'amount'        => Money::decimal($deltaCents),
                'balance_after' => Money::decimal($balance),
                'note'          => mb_substr($note, 0, 255),
            ]);
        });
    }
}
