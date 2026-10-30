<?php
declare(strict_types=1);

namespace MajiLink\Controllers\Api;

use MajiLink\Http\Request;
use MajiLink\Http\Response;
use MajiLink\Middleware\Authenticator;
use MajiLink\Services\LocationService;
use MajiLink\Services\NotificationService;
use MajiLink\Services\OrderService;
use MajiLink\Services\PaymentService;
use MajiLink\Services\PricingService;
use MajiLink\Services\RatingService;
use MajiLink\Services\RecurringService;
use MajiLink\Services\VendorService;
use MajiLink\Support\Validator;

final class CustomerController
{
    public function __construct(private readonly LocationService $locations = new LocationService())
    {
    }

    public function listLocations(): void
    {
        Response::ok(['locations' => $this->locations->list(Authenticator::id())]);
    }

    public function createLocation(Request $request): void
    {
        Response::created($this->locations->create(Authenticator::id(), $request->data()));
    }

    public function updateLocation(Request $request, array $params): void
    {
        Response::ok($this->locations->update(Authenticator::id(), (int) $params['id'], $request->data()));
    }

    public function deleteLocation(array $params): void
    {
        $this->locations->delete(Authenticator::id(), (int) $params['id']);
        Response::ok(['deleted' => true]);
    }

    public function nearbyVendors(Request $request): void
    {
        $locationId = $request->query('location_id');
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        if ($locationId !== null) {
            $location = $this->locations->find(Authenticator::id(), (int) $locationId);
            $lat = $location['lat'];
            $lng = $location['lng'];
        }
        if ($lat === null || $lng === null) {
            throw \MajiLink\Http\HttpException::validation(['location_id' => 'Provide a saved pin or lat/lng.']);
        }
        $input = Validator::validate(
            ['lat' => $lat, 'lng' => $lng, 'radius_km' => $request->query('radius_km')],
            ['lat' => 'required|lat', 'lng' => 'required|lng', 'radius_km' => 'nullable|numeric|min:0.1']
        );

        $result = (new VendorService())->nearby(
            (float) $input['lat'],
            (float) $input['lng'],
            isset($input['radius_km']) ? (float) $input['radius_km'] : null
        );
        Response::ok(['vendors' => $result['vendors']], [
            'radius_km'     => $result['radius_km'],
            'max_radius_km' => $result['max_radius_km'],
            'count'         => count($result['vendors']),
        ]);
    }

    public function showVendor(array $params): void
    {
        Response::ok((new VendorService())->show((int) $params['id']));
    }

    public function createOrder(Request $request): void
    {
        $orders = new OrderService();
        Response::created($orders->create(Authenticator::id(), $request->data()));
    }

    public function listOrders(Request $request): void
    {
        $orders = new OrderService();
        $filter = (string) ($request->query('filter') ?? '');
        Response::ok(['orders' => $orders->listForCustomer(Authenticator::id(), $filter !== '' ? $filter : null)]);
    }

    public function showOrder(array $params): void
    {
        $orders = new OrderService();
        $order = $orders->forCustomer(Authenticator::id(), (int) $params['id']);
        $order['history'] = $orders->history((int) $params['id']);
        Response::ok($order);
    }

    public function cancelOrder(Request $request, array $params): void
    {
        $input = $request->data();
        Response::ok((new OrderService())->cancelByCustomer(Authenticator::id(), (int) $params['id'], $input['reason'] ?? null));
    }

    public function rateOrder(Request $request, array $params): void
    {
        Response::created((new RatingService())->create(Authenticator::id(), (int) $params['id'], $request->data()));
    }

    public function payWithMpesa(Request $request, array $params): void
    {
        $input = $request->data();
        Response::ok((new PaymentService())->initiateStk(Authenticator::id(), (int) $params['id'], $input['phone'] ?? null));
    }

    public function quote(Request $request): void
    {
        $input = Validator::validate($request->data(), [
            'vendor_id'   => 'required|integer|exists:vendors,id',
            'location_id' => 'required|integer|exists:locations,id',
            'quantity'    => 'required|integer',
        ]);
        $vendor = \MajiLink\Support\Db::one('SELECT * FROM vendors WHERE id = ?', [$input['vendor_id']]);
        $location = $this->locations->find(Authenticator::id(), (int) $input['location_id']);
        $quote = (new PricingService())->quote($vendor, (float) $location['lat'], (float) $location['lng'], (int) $input['quantity']);
        unset($quote['_cents']);
        Response::ok($quote);
    }

    public function listRecurring(): void
    {
        Response::ok(['schedules' => (new RecurringService())->list(Authenticator::id())]);
    }

    public function createRecurring(Request $request): void
    {
        Response::created((new RecurringService())->create(Authenticator::id(), $request->data()));
    }

    public function updateRecurring(Request $request, array $params): void
    {
        Response::ok((new RecurringService())->update(Authenticator::id(), (int) $params['id'], $request->data()));
    }

    public function deleteRecurring(array $params): void
    {
        (new RecurringService())->delete(Authenticator::id(), (int) $params['id']);
        Response::ok(['deleted' => true]);
    }

    public function notifications(): void
    {
        Response::ok(['notifications' => (new NotificationService())->forUser(Authenticator::id())]);
    }

    public function readNotification(array $params): void
    {
        (new NotificationService())->markRead(Authenticator::id(), (int) $params['id']);
        Response::ok(['read' => true]);
    }
}
