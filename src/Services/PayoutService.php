<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Money;
use MajiLink\Support\Validator;

final class PayoutService
{
    public function __construct(
        private readonly WalletService $wallet = new WalletService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditService $audit = new AuditService()
    ) {
    }

    public function request(int $vendorId, array $data): array
    {
        $input = Validator::validate($data, [
            'amount' => 'required|numeric|min:1',
        ]);
        $amount = Money::cents($input['amount']);
        $summary = $this->wallet->summary($vendorId);
        $available = Money::cents($summary['available']);
        if ($amount > $available) {
            throw HttpException::validation(['amount' => 'You can request up to KES ' . $summary['available'] . '.']);
        }
        $requestedId = (int) Db::value('SELECT id FROM payout_statuses WHERE name = ?', ['Requested']);

        $id = Db::insert('payouts', [
            'vendor_id'    => $vendorId,
            'amount'       => Money::decimal($amount),
            'status_id'    => $requestedId,
            'requested_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->record(null, 'payouts.request', 'payouts', $id, ['vendor_id' => $vendorId, 'amount' => Money::decimal($amount)]);

        return $this->find($id);
    }

    public function listForVendor(int $vendorId): array
    {
        return Db::all(
            'SELECT p.id, p.amount, s.name AS status, p.mpesa_receipt, p.requested_at, p.processed_at
             FROM payouts p JOIN payout_statuses s ON s.id = p.status_id
             WHERE p.vendor_id = ? ORDER BY p.id DESC',
            [$vendorId]
        );
    }

    public function listForAdmin(?string $status = null): array
    {
        $sql = 'SELECT p.*, s.name AS status, v.business_name, v.payout_phone,
                       CONCAT(u.first_name, " ", u.last_name) AS vendor_owner
                FROM payouts p
                JOIN payout_statuses s ON s.id = p.status_id
                JOIN vendors v ON v.id = p.vendor_id
                JOIN users u ON u.id = v.user_id
                WHERE 1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND s.name = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY FIELD(s.name, "Requested", "Approved", "Paid", "Rejected"), p.id DESC LIMIT 200';
        return Db::all($sql, $params);
    }

    public function find(int $payoutId): array
    {
        $row = Db::one(
            'SELECT p.*, s.name AS status, v.business_name, v.payout_phone FROM payouts p
             JOIN payout_statuses s ON s.id = p.status_id
             JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ?',
            [$payoutId]
        );
        if ($row === null) {
            throw HttpException::notFound('Payout not found.');
        }
        return $row;
    }

    public function approve(int $payoutId, int $adminId, ?string $ip = null): void
    {
        $payout = $this->find($payoutId);
        if ($payout['status'] !== 'Requested') {
            throw HttpException::conflict('Only requested payouts can be approved.');
        }
        $approvedId = (int) Db::value('SELECT id FROM payout_statuses WHERE name = ?', ['Approved']);
        Db::update('payouts', ['status_id' => $approvedId, 'processed_by' => $adminId], 'id = ?', [$payoutId]);
        $this->audit->record($adminId, 'payouts.approve', 'payouts', $payoutId, [], $ip);
    }

    public function reject(int $payoutId, int $adminId, string $reason, ?string $ip = null): void
    {
        if (trim($reason) === '') {
            throw HttpException::validation(['reason' => 'A reason is required.']);
        }
        $payout = $this->find($payoutId);
        if (!in_array($payout['status'], ['Requested', 'Approved'], true)) {
            throw HttpException::conflict('This payout cannot be rejected.');
        }
        $rejectedId = (int) Db::value('SELECT id FROM payout_statuses WHERE name = ?', ['Rejected']);
        Db::update('payouts', [
            'status_id'    => $rejectedId,
            'processed_by' => $adminId,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$payoutId]);

        $vendorUser = Db::value('SELECT user_id FROM vendors WHERE id = ?', [$payout['vendor_id']]);
        if ($vendorUser !== null) {
            $this->notifications->notifyVendorUser((int) $vendorUser, 'Payout rejected', 'Reason: ' . $reason, ['type' => 'payout_rejected']);
        }
        $this->audit->record($adminId, 'payouts.reject', 'payouts', $payoutId, ['reason' => $reason], $ip);
    }

    /** Admin records the M-Pesa B2C receipt after paying the vendor manually. */
    public function markPaid(int $payoutId, int $adminId, string $receipt, ?string $ip = null): void
    {
        $payout = $this->find($payoutId);
        if ($payout['status'] !== 'Approved') {
            throw HttpException::conflict('Approve the payout before marking it paid.');
        }
        if (trim($receipt) === '') {
            throw HttpException::validation(['mpesa_receipt' => 'Enter the M-Pesa receipt number.']);
        }
        $paidId = (int) Db::value('SELECT id FROM payout_statuses WHERE name = ?', ['Paid']);
        $amountCents = Money::cents($payout['amount']);

        Db::transaction(function () use ($payout, $paidId, $adminId, $receipt, $amountCents): void {
            Db::update('payouts', [
                'status_id'    => $paidId,
                'mpesa_receipt'=> $receipt,
                'processed_by' => $adminId,
                'processed_at' => gmdate('Y-m-d H:i:s'),
            ], 'id = ?', [$payout['id']]);
            $this->wallet->debit(
                (int) $payout['vendor_id'],
                'Payout',
                null,
                $amountCents,
                'Payout #' . $payout['id'] . ' paid via M-Pesa ' . $receipt
            );
        });

        $vendorUser = Db::value('SELECT user_id FROM vendors WHERE id = ?', [$payout['vendor_id']]);
        if ($vendorUser !== null) {
            $this->notifications->notifyVendorUser(
                (int) $vendorUser,
                'Payout paid',
                'KES ' . $payout['amount'] . ' sent to ' . $payout['payout_phone'] . '. Receipt ' . $receipt . '.',
                ['type' => 'payout_paid']
            );
        }
        $this->audit->record($adminId, 'payouts.mark_paid', 'payouts', $payoutId, ['receipt' => $receipt], $ip);
    }
}
