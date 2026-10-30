<?php
declare(strict_types=1);

namespace MajiLink\Controllers\Api;

use MajiLink\Http\HttpException;
use MajiLink\Http\Request;
use MajiLink\Http\Response;
use MajiLink\Middleware\Authenticator;
use MajiLink\Services\OrderService;
use MajiLink\Services\PayoutService;
use MajiLink\Services\RatingService;
use MajiLink\Services\VendorService;
use MajiLink\Services\WalletService;
use MajiLink\Support\Validator;

final class VendorController
{
    public function __construct(private readonly VendorService $vendors = new VendorService())
    {
    }

    public function apply(Request $request): void
    {
        Response::created($this->vendors->apply(Authenticator::id(), $request->data()));
    }

    public function application(): void
    {
        $application = $this->vendors->application(Authenticator::id());
        Response::ok(['application' => $application]);
    }

    public function uploadDocument(Request $request): void
    {
        $documentType = (int) ($request->post['document_type_id'] ?? 0);
        $file = $request->file('file');
        if ($file === null) {
            throw HttpException::validation(['file' => 'Attach an image or PDF.']);
        }
        if ($documentType <= 0) {
            throw HttpException::validation(['document_type_id' => 'Choose a document type.']);
        }
        Response::created($this->vendors->addDocument(Authenticator::id(), $documentType, $file));
    }

    public function profile(): void
    {
        $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok(['application' => $this->vendors->application(Authenticator::id())]);
    }

    public function updateProfile(Request $request): void
    {
        Response::ok($this->vendors->updateProfile(Authenticator::id(), $request->data()));
    }

    public function setOnline(Request $request): void
    {
        $input = Validator::validate($request->data(), ['is_online' => 'required|bool']);
        Response::ok($this->vendors->setOnline(Authenticator::id(), (bool) (int) $input['is_online']));
    }

    public function heartbeat(): void
    {
        Response::ok($this->vendors->heartbeat(Authenticator::id()));
    }

    public function orders(Request $request): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        $filter = (string) ($request->query('filter') ?? '');
        Response::ok(['orders' => (new OrderService())->listForVendor((int) $vendor['id'], $filter !== '' ? $filter : null)]);
    }

    public function showOrder(array $params): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        $orders = new OrderService();
        $order = $orders->forVendor((int) $vendor['id'], (int) $params['id']);
        $order['history'] = $orders->history((int) $params['id']);
        Response::ok($order);
    }

    public function accept(array $params): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok((new OrderService())->accept((int) $vendor['id'], (int) $params['id']));
    }

    public function decline(Request $request, array $params): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        $reason = $request->data()['reason'] ?? null;
        Response::ok((new OrderService())->decline((int) $vendor['id'], (int) $params['id'], $reason));
    }

    public function onTheWay(array $params): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok((new OrderService())->markOnTheWay((int) $vendor['id'], (int) $params['id']));
    }

    public function delivered(array $params): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok((new OrderService())->markDelivered((int) $vendor['id'], (int) $params['id']));
    }

    public function cancelOrder(Request $request, array $params): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        $input = Validator::validate($request->data(), ['reason' => 'required|string|min:3|max:255']);
        Response::ok((new OrderService())->cancelByVendor((int) $vendor['id'], (int) $params['id'], (string) $input['reason']));
    }

    public function wallet(): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok((new WalletService())->summary((int) $vendor['id']));
    }

    public function transactions(): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok(['transactions' => (new WalletService())->transactions((int) $vendor['id'])]);
    }

    public function payouts(): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok(['payouts' => (new PayoutService())->listForVendor((int) $vendor['id'])]);
    }

    public function requestPayout(Request $request): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::created((new PayoutService())->request((int) $vendor['id'], $request->data()));
    }

    public function ratings(): void
    {
        $vendor = $this->vendors->requireApprovedVendor(Authenticator::id());
        Response::ok(['ratings' => (new RatingService())->forVendor((int) $vendor['id'])]);
    }
}
