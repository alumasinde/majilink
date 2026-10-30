<?php
declare(strict_types=1);

namespace MajiLink\Controllers\Api;

use MajiLink\Http\Request;
use MajiLink\Http\Response;
use MajiLink\Middleware\Authenticator;
use MajiLink\Services\NotificationService;
use MajiLink\Services\OrderService;
use MajiLink\Services\PaymentService;
use MajiLink\Services\PayoutService;
use MajiLink\Services\ReportService;
use MajiLink\Services\VendorService;
use MajiLink\Support\Validator;

final class AdminApiController
{
    public function applications(Request $request): void
    {
        $status = (string) ($request->query('status') ?? 'Pending');
        Response::ok(['applications' => (new VendorService())->listForAdmin($status)]);
    }

    public function review(Request $request, array $params): void
    {
        $input = Validator::validate($request->data(), [
            'decision' => 'required|in:approve,reject',
            'reason'   => 'nullable|string|max:255',
        ]);
        Response::ok((new VendorService())->review(
            (int) $params['id'],
            Authenticator::id(),
            (string) $input['decision'],
            $input['reason'] ?? null,
            $request->ip()
        ));
    }

    public function vendors(Request $request): void
    {
        Response::ok(['vendors' => (new VendorService())->listForAdmin(
            (string) ($request->query('status') ?? ''),
            (string) ($request->query('source') ?? ''),
            (string) ($request->query('q') ?? '')
        )]);
    }

    public function suspend(Request $request, array $params): void
    {
        $input = Validator::validate($request->data(), [
            'suspended' => 'required|bool',
            'reason'    => 'nullable|string|max:255',
        ]);
        Response::ok((new VendorService())->setSuspended(
            (int) $params['id'],
            Authenticator::id(),
            (bool) (int) $input['suspended'],
            $input['reason'] ?? null,
            $request->ip()
        ));
    }

    public function orders(Request $request): void
    {
        Response::ok(['orders' => (new OrderService())->listForAdmin(
            (string) ($request->query('status') ?? ''),
            (string) ($request->query('q') ?? ''),
            (string) ($request->query('from') ?? ''),
            (string) ($request->query('to') ?? '')
        )]);
    }

    public function order(array $params): void
    {
        $orders = new OrderService();
        $row = $orders->row((int) $params['id']);
        $payload = $orders->present($row, 'admin');
        $payload['history'] = $orders->history((int) $params['id']);
        Response::ok($payload);
    }

    public function overrideOrder(Request $request, array $params): void
    {
        $input = Validator::validate($request->data(), [
            'status' => 'required|string',
            'reason' => 'required|string|max:255',
        ]);
        Response::ok((new OrderService())->override(
            (int) $params['id'],
            Authenticator::id(),
            (string) $input['status'],
            (string) $input['reason'],
            $request->ip()
        ));
    }

    public function payments(Request $request): void
    {
        Response::ok(['payments' => (new PaymentService())->listForAdmin(
            (string) ($request->query('status') ?? ''),
            (string) ($request->query('method') ?? ''),
            (string) ($request->query('q') ?? '')
        )]);
    }

    public function payouts(Request $request): void
    {
        Response::ok(['payouts' => (new PayoutService())->listForAdmin((string) ($request->query('status') ?? ''))]);
    }

    public function processPayout(Request $request, array $params): void
    {
        $input = Validator::validate($request->data(), [
            'action' => 'required|in:approve,reject,paid',
            'reason' => 'nullable|string|max:255',
            'mpesa_receipt' => 'nullable|string|max:40',
        ]);
        $service = new PayoutService();
        $id = (int) $params['id'];
        match ($input['action']) {
            'approve' => $service->approve($id, Authenticator::id(), $request->ip()),
            'reject'  => $service->reject($id, Authenticator::id(), (string) ($input['reason'] ?? ''), $request->ip()),
            'paid'    => $service->markPaid($id, Authenticator::id(), (string) ($input['mpesa_receipt'] ?? ''), $request->ip()),
        };
        Response::ok(['processed' => true]);
    }

    public function reports(Request $request): void
    {
        $reports = new ReportService();
        $from = (string) ($request->query('from') ?? gmdate('Y-m-d', strtotime('-14 days')));
        $to = (string) ($request->query('to') ?? gmdate('Y-m-d'));
        Response::ok([
            'dashboard'   => $reports->dashboard(),
            'by_day'      => $reports->ordersByDay($from, $to),
            'by_area'     => $reports->ordersByArea($from, $to),
            'vendors'     => $reports->vendorPerformance($from, $to),
            'revenue'     => $reports->revenueSummary($from, $to),
            'range'       => ['from' => $from, 'to' => $to],
        ]);
    }

    public function broadcast(Request $request): void
    {
        $input = Validator::validate($request->data(), [
            'audience' => 'required|in:customers,vendors,all',
            'title'    => 'required|string|max:120',
            'body'     => 'required|string|max:500',
        ]);
        $roles = match ($input['audience']) {
            'customers' => ['customer'],
            'vendors'   => ['vendor'],
            default     => ['customer', 'vendor'],
        };
        $count = (new NotificationService())->broadcast($roles, (string) $input['title'], (string) $input['body']);
        (new \MajiLink\Services\AuditService())->record(Authenticator::id(), 'notifications.broadcast', 'notifications', null, $input, $request->ip());
        Response::ok(['sent_to' => $count]);
    }
}
