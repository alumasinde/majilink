<?php
declare(strict_types=1);

namespace MajiLink\Tests\Unit;

use MajiLink\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testDecimalStringsConvertToExactCents(): void
    {
        $this->assertSame(6000, Money::cents('60.00'));
        $this->assertSame(1999, Money::cents('19.99'));
        $this->assertSame(0, Money::cents('0.00'));
        $this->assertSame(0, Money::cents(null));
    }

    public function testCentsConvertBackToTwoDecimalPlaces(): void
    {
        $this->assertSame('60.00', Money::decimal(6000));
        $this->assertSame('0.05', Money::decimal(5));
        $this->assertSame('170.50', Money::decimal(17050));
    }

    /** A negative balance is a real outcome: a commission debit can outrun earnings. */
    public function testNegativeAmountsKeepTheirSign(): void
    {
        $this->assertSame('-12.34', Money::decimal(-1234));
    }

    public function testDisplayPrefixesTheCurrency(): void
    {
        $this->assertSame('KES 1,234.50', Money::display(123450));
    }

    public function testPercentOfRoundsToTheNearestCent(): void
    {
        $this->assertSame(2400, Money::percentOf(24000, 10.0));
        // 30.875 rounds up, so a 2.5% commission on KES 12.35 is KES 0.31.
        $this->assertSame(31, Money::percentOf(1235, 2.5));
        $this->assertSame(0, Money::percentOf(0, 10.0));
    }

    public function testFractionalCentsRoundRatherThanTruncate(): void
    {
        $this->assertSame(6001, Money::cents('60.005'));
    }

    /** Decimal in, cents, decimal out must be lossless for real prices. */
    public function testRoundTripIsStable(): void
    {
        foreach (['60.00', '85.50', '120.99', '5.05'] as $price) {
            $this->assertSame($price, Money::decimal(Money::cents($price)));
        }
    }
}
