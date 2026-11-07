<?php
declare(strict_types=1);

namespace MajiLink\Tests\Unit;

use MajiLink\Support\Geo;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase
{
    public function testSamePointIsZeroDistance(): void
    {
        $this->assertSame(0.0, Geo::distanceKm(-1.2921, 36.8219, -1.2921, 36.8219));
    }

    /** One degree of latitude is a fixed fraction of the Earth's circumference. */
    public function testOneDegreeOfLatitudeIsAbout111km(): void
    {
        $this->assertEqualsWithDelta(111.195, Geo::distanceKm(0.0, 0.0, 1.0, 0.0), 0.01);
    }

    public function testDistanceIsSymmetric(): void
    {
        $a = Geo::distanceKm(-1.2921, 36.8219, -1.2658, 36.8108);
        $b = Geo::distanceKm(-1.2658, 36.8108, -1.2921, 36.8219);

        $this->assertSame($a, $b);
    }

    public function testNairobiCbdToWestlandsIsRoughlyThreeKilometres(): void
    {
        $distance = Geo::distanceKm(-1.2921, 36.8219, -1.2658, 36.8108);

        $this->assertGreaterThan(2.9, $distance);
        $this->assertLessThan(3.5, $distance);
    }

    public function testDistanceIsRoundedToThreeDecimals(): void
    {
        $distance = Geo::distanceKm(-1.2921, 36.8219, -1.2831, 36.8219);

        $this->assertSame(round($distance, 3), $distance);
    }

    public function testBoundingBoxIsCentredOnThePoint(): void
    {
        $box = Geo::boundingBox(-1.2921, 36.8219, 3.0);

        $this->assertEqualsWithDelta(-1.2921, ($box['min_lat'] + $box['max_lat']) / 2, 0.000001);
        $this->assertEqualsWithDelta(36.8219, ($box['min_lng'] + $box['max_lng']) / 2, 0.000001);
    }

    public function testBoundingBoxContainsPointsInsideTheRadiusAndNotBeyondIt(): void
    {
        $box = Geo::boundingBox(0.0, 0.0, 3.0);

        // 2 km north sits inside a 3 km box; 10 km north does not.
        $inside = 2.0 / 111.195;
        $outside = 10.0 / 111.195;

        $this->assertTrue($inside < $box['max_lat']);
        $this->assertFalse($outside < $box['max_lat']);
    }

    /** Far from the equator the same kilometre radius spans more longitude. */
    public function testLongitudeDeltaWidensTowardsThePoles(): void
    {
        $equator = Geo::boundingBox(0.0, 0.0, 3.0);
        $highLat = Geo::boundingBox(60.0, 0.0, 3.0);

        $equatorSpan = $equator['max_lng'] - $equator['min_lng'];
        $highSpan = $highLat['max_lng'] - $highLat['min_lng'];

        $this->assertEqualsWithDelta($equatorSpan * 2, $highSpan, 0.0005);
    }
}
