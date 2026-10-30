<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Geo;
use MajiLink\Support\Money;

final class PricingService
{
    public function __construct(private readonly SettingsService $settings = new SettingsService())
    {
    }

    public function assertQuantity(int $quantity): void
    {
        $min = $this->settings->int('min_quantity', 1);
        $max = $this->settings->int('max_quantity', 20);
        if ($quantity < $min || $quantity > $max) {
            throw HttpException::validation(['quantity' => "Quantity must be between {$min} and {$max} jerrycans."]);
        }
    }

    public function quote(array $vendor, float $deliveryLat, float $deliveryLng, int $quantity): array
    {
        $this->assertQuantity($quantity);

        $unitPrice = Money::cents($vendor['price_per_20l']);
        $subtotal = $unitPrice * $quantity;

        $baseFee = Money::cents($this->settings->float('delivery_base_fee', 0));
        $perKm = Money::cents($this->settings->float('delivery_fee_per_km', 0));
        $distance = Geo::distanceKm((float) $vendor['base_lat'], (float) $vendor['base_lng'], $deliveryLat, $deliveryLng);
        $deliveryFee = $baseFee + (int) round($perKm * $distance);

        $total = $subtotal + $deliveryFee;
        $commission = Money::percentOf($subtotal, $this->settings->float('commission_percent', 0));

        return [
            'quantity'         => $quantity,
            'unit_price'       => Money::decimal($unitPrice),
            'subtotal'         => Money::decimal($subtotal),
            'delivery_fee'     => Money::decimal($deliveryFee),
            'total'            => Money::decimal($total),
            'commission_amount'=> Money::decimal($commission),
            'distance_km'      => $distance,
            '_cents'           => [
                'unit_price' => $unitPrice,
                'subtotal'   => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total'      => $total,
                'commission' => $commission,
            ],
        ];
    }

    public function minimumOrderCents(array $vendor): int
    {
        return Money::cents($vendor['price_per_20l']) * $this->settings->int('min_quantity', 1);
    }
}
