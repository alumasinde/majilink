<?php
declare(strict_types=1);

namespace MajiLink\Tests\Service;

use MajiLink\Services\PricingService;
use MajiLink\Support\Geo;
use MajiLink\Support\Money;
use MajiLink\Tests\Support\MajiTestCase;

/**
 * Pricing is the one rule every order depends on, so it is pinned down exactly:
 * subtotal = unit price x quantity, delivery fee = base + per-km, commission a
 * percentage of the subtotal only.
 */
final class PricingTest extends MajiTestCase
{
    private const LAT = -1.2921000;
    private const LNG = 36.8219000;

    private function vendor(float $price = 60.00): array
    {
        return [
            'price_per_20l'      => number_format($price, 2, '.', ''),
            'base_lat'           => self::LAT,
            'base_lng'           => self::LNG,
            'delivery_radius_km' => '3.00',
        ];
    }

    private function pricing(): PricingService
    {
        return new PricingService();
    }

    public function testQuoteAtTheVendorsPinChargesOnlyTheBaseFee(): void
    {
        $quote = $this->pricing()->quote($this->vendor(), self::LAT, self::LNG, 4);

        $this->assertSame('240.00', $quote['subtotal']);
        $this->assertSame('50.00', $quote['delivery_fee']);
        $this->assertSame('290.00', $quote['total']);
        $this->assertSame('24.00', $quote['commission_amount']);
        $this->assertSame(0.0, $quote['distance_km']);
        $this->assertSame('60.00', $quote['unit_price']);
    }

    public function testDeliveryFeeGrowsWithDistance(): void
    {
        // About 1 km north of the vendor.
        $pin = self::LAT + 0.008993;
        $quote = $this->pricing()->quote($this->vendor(), $pin, self::LNG, 1);

        $expectedFee = 5000 + (int) round(2000 * $quote['distance_km']);

        $this->assertGreaterThan(0.9, $quote['distance_km']);
        $this->assertLessThan(1.1, $quote['distance_km']);
        $this->assertSame(Money::decimal($expectedFee), $quote['delivery_fee']);
        $this->assertSame(Money::decimal(6000 + $expectedFee), $quote['total']);
    }

    /** Zero fees are a supported configuration, not an error. */
    public function testFreeDeliverySettingProducesNoFee(): void
    {
        $this->setting('delivery_base_fee', '0');
        $this->setting('delivery_fee_per_km', '0');

        $quote = $this->pricing()->quote($this->vendor(), self::LAT + 0.02, self::LNG, 2);

        $this->assertSame('0.00', $quote['delivery_fee']);
        $this->assertSame('120.00', $quote['total']);
    }

    public function testCommissionIsChargedOnTheSubtotalNotTheDeliveryFee(): void
    {
        $quote = $this->pricing()->quote($this->vendor(), self::LAT, self::LNG, 2);

        // 10% of 120.00 = 12.00, and the 50.00 delivery fee is untouched.
        $this->assertSame('12.00', $quote['commission_amount']);
        $this->assertSame('170.00', $quote['total']);
    }

    public function testCommissionRoundsToTheNearestCent(): void
    {
        $quote = $this->pricing()->quote($this->vendor(19.99), self::LAT, self::LNG, 3);

        // 59.97 x 10% = 5.997, which must round up to 6.00 rather than truncate.
        $this->assertSame('59.97', $quote['subtotal']);
        $this->assertSame('6.00', $quote['commission_amount']);
    }

    public function testCommissionPercentCanBeChangedInSettings(): void
    {
        $this->setting('commission_percent', '7.5');

        $quote = $this->pricing()->quote($this->vendor(), self::LAT, self::LNG, 4);

        $this->assertSame('18.00', $quote['commission_amount']); // 240 x 7.5%
    }

    public function testQuantityBelowTheMinimumIsRejected(): void
    {
        $error = $this->assertHttpStatus(422, fn () => $this->pricing()->assertQuantity(0));

        $this->assertArrayHasKey('quantity', $error->fields);
    }

    public function testQuantityAboveTheMaximumIsRejected(): void
    {
        $error = $this->assertHttpStatus(422, fn () => $this->pricing()->assertQuantity(21));

        $this->assertArrayHasKey('quantity', $error->fields);
    }

    public function testQuantityLimitsFollowSettings(): void
    {
        $this->setting('min_quantity', '2');
        $this->setting('max_quantity', '5');

        $this->assertHttpStatus(422, fn () => $this->pricing()->assertQuantity(1));
        $this->assertHttpStatus(422, fn () => $this->pricing()->assertQuantity(6));
        $this->pricing()->assertQuantity(3);
        $this->addToAssertionCount(1);
    }

    public function testCentsViewMatchesTheDecimalFields(): void
    {
        $quote = $this->pricing()->quote($this->vendor(), self::LAT + 0.01, self::LNG, 3);
        $cents = $quote['_cents'];

        $this->assertSame(Money::cents($quote['total']), $cents['total']);
        $this->assertSame($cents['subtotal'] + $cents['delivery_fee'], $cents['total']);
        $this->assertSame(Money::cents($quote['commission_amount']), $cents['commission']);
    }

    public function testDistanceMatchesTheSharedGeoHelper(): void
    {
        $pinLat = self::LAT + 0.005;
        $quote = $this->pricing()->quote($this->vendor(), $pinLat, self::LNG, 1);

        $this->assertSame(Geo::distanceKm(self::LAT, self::LNG, $pinLat, self::LNG), $quote['distance_km']);
    }

    public function testMinimumOrderUsesTheMinimumQuantity(): void
    {
        $this->assertSame(6000, $this->pricing()->minimumOrderCents($this->vendor()));

        $this->setting('min_quantity', '3');
        $this->assertSame(18000, $this->pricing()->minimumOrderCents($this->vendor()));
    }
}
