<?php
declare(strict_types=1);

namespace MajiLink\Tests\Service;

use MajiLink\Services\LocationService;
use MajiLink\Services\RecurringService;
use MajiLink\Support\Db;
use MajiLink\Tests\Support\MajiTestCase;

/**
 * MySQL returns TINYINT(1) columns as numbers, but both clients type those fields
 * as JSON booleans. Handing back 1 instead of true crashed the Android home,
 * schedule and account screens, so the cast is pinned here.
 */
final class PayloadShapeTest extends MajiTestCase
{
    public function test_a_location_reports_is_default_as_a_boolean(): void
    {
        $user = $this->createUser();
        $service = new LocationService();

        $created = $service->create((int) $user['id'], [
            'alias' => "Albert's House",
            'lat'   => -1.2921,
            'lng'   => 36.8219,
        ]);

        $this->assertIsBool($created['is_default']);
        $this->assertTrue($created['is_default']);

        $listed = $service->list((int) $user['id']);
        $this->assertIsBool($listed[0]['is_default']);
        $this->assertStringContainsString('"is_default":true', (string) json_encode($listed));
    }

    public function test_a_second_location_is_not_default_and_still_encodes_as_a_boolean(): void
    {
        $user = $this->createUser();
        $service = new LocationService();

        $service->create((int) $user['id'], ['alias' => 'Home', 'lat' => -1.2921, 'lng' => 36.8219]);
        $office = $service->create((int) $user['id'], ['alias' => 'Office', 'lat' => -1.3000, 'lng' => 36.8000]);

        $this->assertFalse($office['is_default']);
        $this->assertStringContainsString('"is_default":false', (string) json_encode($office));
    }

    public function test_a_recurring_schedule_reports_is_active_as_a_boolean(): void
    {
        $customer = $this->createUser();
        $vendor = $this->createVendor();
        $location = $this->createLocation((int) $customer['id']);

        $id = Db::insert('recurring_orders', [
            'customer_id'  => (int) $customer['id'],
            'vendor_id'    => (int) $vendor['id'],
            'location_id'  => (int) $location['id'],
            'frequency_id' => $this->lookup('frequencies', 'Weekly'),
            'quantity'     => 2,
            'next_run_at'  => gmdate('Y-m-d H:i:s', time() + 86400),
            'is_active'    => 1,
        ]);

        $service = new RecurringService();
        $listed = $service->list((int) $customer['id']);
        $found = $service->find((int) $customer['id'], $id);

        $this->assertIsBool($listed[0]['is_active']);
        $this->assertTrue($listed[0]['is_active']);
        $this->assertIsBool($found['is_active']);
        $this->assertStringContainsString('"is_active":true', (string) json_encode($listed[0]));
    }
}
