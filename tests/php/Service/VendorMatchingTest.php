<?php
declare(strict_types=1);

namespace MajiLink\Tests\Service;

use MajiLink\Services\VendorService;
use MajiLink\Support\Db;
use MajiLink\Tests\Support\MajiTestCase;

/**
 * Who a customer may see: approved, online, heartbeat-fresh, inside the customer
 * radius, inside the vendor's own radius, and inside opening hours.
 */
final class VendorMatchingTest extends MajiTestCase
{
    private const LAT = -1.2921000;
    private const LNG = 36.8219000;

    /** Roughly one kilometre of latitude. */
    private const KM = 0.008993;

    private function nearby(?float $radius = null): array
    {
        return (new VendorService())->nearby(self::LAT, self::LNG, $radius);
    }

    private function ids(array $payload): array
    {
        return array_map(static fn (array $v) => (int) $v['id'], $payload['vendors']);
    }

    private function vendorAt(float $southKm, array $overrides = []): array
    {
        return $this->createVendor(array_merge([
            'base_lat' => self::LAT + $southKm * self::KM,
            'base_lng' => self::LNG,
        ], $overrides));
    }

    public function testOnlyApprovedOnlineFreshVendorsAreListed(): void
    {
        $live = $this->vendorAt(0.0);
        $this->vendorAt(0.5, ['is_online' => 0]);
        $this->vendorAt(0.6, ['last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $this->vendorAt(0.7, ['application_status_id' => $this->lookup('vendor_application_statuses', 'Pending')]);
        $this->vendorAt(0.8, ['application_status_id' => $this->lookup('vendor_application_statuses', 'Suspended')]);

        $payload = $this->nearby();

        $this->assertSame([(int) $live['id']], $this->ids($payload));
    }

    public function testResultsAreSortedByDistanceThenPrice(): void
    {
        $near = $this->vendorAt(0.0, ['price_per_20l' => '80.00']);
        $mid = $this->vendorAt(0.8, ['price_per_20l' => '60.00']);
        $far = $this->vendorAt(1.6, ['price_per_20l' => '40.00']);

        $this->assertSame(
            [(int) $near['id'], (int) $mid['id'], (int) $far['id']],
            $this->ids($this->nearby())
        );
    }

    public function testTwoVendorsAtTheSameDistanceAreOrderedByPrice(): void
    {
        $pricey = $this->vendorAt(1.0, ['price_per_20l' => '90.00']);
        $cheap = $this->vendorAt(1.0, ['price_per_20l' => '55.00']);

        $this->assertSame([(int) $cheap['id'], (int) $pricey['id']], $this->ids($this->nearby()));
    }

    public function testRequestedRadiusIsClampedToTheConfiguredMaximum(): void
    {
        $payload = $this->nearby(50.0);

        $this->assertSame(5.0, (float) $payload['radius_km']);
        $this->assertSame(5.0, (float) $payload['max_radius_km']);
    }

    public function testVendorBeyondTheClientRadiusIsExcluded(): void
    {
        $inside = $this->vendorAt(2.0);
        // Its own radius must cover 4 km, otherwise the vendor radius test below
        // would be doing the excluding rather than the customer radius.
        $outside = $this->vendorAt(4.0, ['delivery_radius_km' => '5.00']);

        $threeKm = $this->ids($this->nearby());
        $this->assertSame([(int) $inside['id']], $threeKm);
        $this->assertNotContains((int) $outside['id'], $threeKm);

        $fiveKm = $this->ids($this->nearby(5.0));
        $this->assertContains((int) $outside['id'], $fiveKm);
    }

    public function testVendorOwnDeliveryRadiusLimitsTheSearch(): void
    {
        // Two kilometres from the customer, but only willing to deliver one.
        $vendor = $this->vendorAt(2.0, ['delivery_radius_km' => '1.00']);

        $this->assertNotContains((int) $vendor['id'], $this->ids($this->nearby(5.0)));

        Db::update('vendors', ['delivery_radius_km' => '3.00'], 'id = ?', [$vendor['id']]);

        $this->assertContains((int) $vendor['id'], $this->ids($this->nearby(5.0)));
    }

    public function testDefaultRadiusIsUsedWhenTheClientSendsNone(): void
    {
        $near = $this->vendorAt(1.0);
        $beyondDefault = $this->vendorAt(4.0, ['delivery_radius_km' => '5.00']);

        $default = $this->ids($this->nearby());
        $this->assertSame([(int) $near['id']], $default);
        $this->assertNotContains((int) $beyondDefault['id'], $default);
    }

    public function testZeroOrNegativeRadiusFallsBackToTheDefault(): void
    {
        $this->vendorAt(1.0);

        $this->assertSame(3.0, (float) $this->nearby(0.0)['radius_km']);
        $this->assertSame(3.0, (float) $this->nearby(-2.0)['radius_km']);
    }

    public function testVendorOutsideOpeningHoursIsNotListed(): void
    {
        $local = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi'));
        $vendor = $this->vendorAt(0.5, [
            'opens_at' => $local->modify('-2 hours')->format('H:i:s'),
            'closes_at' => $local->modify('-1 hour')->format('H:i:s'),
        ]);

        $this->assertNotContains((int) $vendor['id'], $this->ids($this->nearby()));

        Db::update('vendors', ['opens_at' => '00:00:00', 'closes_at' => '23:59:00'], 'id = ?', [$vendor['id']]);

        $this->assertContains((int) $vendor['id'], $this->ids($this->nearby()));
    }

    public function testVendorWithoutHoursIsAlwaysOpen(): void
    {
        $vendor = $this->vendorAt(0.5, ['opens_at' => null, 'closes_at' => null]);

        $this->assertContains((int) $vendor['id'], $this->ids($this->nearby()));
    }

    public function testDistanceAndEtaAreReturnedForEachVendor(): void
    {
        $vendor = $this->vendorAt(1.0);

        $row = $this->nearby()['vendors'][0];

        $this->assertSame((int) $vendor['id'], (int) $row['id']);
        $this->assertEqualsWithDelta(1.0, (float) $row['distance_km'], 0.05);
        $this->assertGreaterThanOrEqual(1, (int) $row['eta_minutes']);
        $this->assertGreaterThanOrEqual((float) $row['distance_km'], (float) $row['road_distance_km']);
    }

    public function testHeartbeatTimeoutIsReadFromSettings(): void
    {
        $vendor = $this->vendorAt(0.5, [
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 10 * 60),
        ]);

        $this->assertContains((int) $vendor['id'], $this->ids($this->nearby()));

        $this->setting('heartbeat_timeout_minutes', '5');

        $this->assertNotContains((int) $vendor['id'], $this->ids($this->nearby()));
    }

    public function testVendorDetailShowsPriceSourceAndOnlineState(): void
    {
        $vendor = $this->vendorAt(0.5);

        $detail = (new VendorService())->show((int) $vendor['id']);

        $this->assertSame($vendor['business_name'], $detail['business_name']);
        $this->assertSame('60.00', $detail['price_per_20l']);
        $this->assertSame('Borehole', $detail['water_source']);
        $this->assertTrue($detail['is_online']);
    }
}
