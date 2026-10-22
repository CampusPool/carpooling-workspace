<?php

namespace STS\Mobility\Journeys;

/** Integer demo amounts only. No production tariff or payment authorization. */
final class DemoFarePolicy
{
    public static function quote(string $vehicle, string $choice, int $ridingMeters): array
    {
        if (! in_array($vehicle, ['car', 'bike'], true) || ! in_array($choice, ['DIRECT_RIDER_PICKUP', 'ON_ROUTE_MEETING_POINT'], true)
            || $ridingMeters < 1 || $ridingMeters > 100000) {
            throw new \InvalidArgumentException('DEMO_FARE_INPUT_INVALID');
        }
        $version = config('mobility_routing.demo_pricing_version', 'demo-v1');
        $rate = config('mobility_routing.demo_'.$vehicle.'_minor_per_km', $vehicle === 'car' ? 800 : 400);
        $extra = config('mobility_routing.demo_'.$vehicle.'_direct_surcharge_minor', $vehicle === 'car' ? 2000 : 1000);
        foreach ([$rate, $extra] as $amount) {
            if ((! is_int($amount) && ! (is_string($amount) && ctype_digit($amount))) || (int) $amount < 1 || (int) $amount > 100000) {
                throw new \RuntimeException('DEMO_PRICING_MISCONFIGURED');
            }
        }
        if (! is_string($version) || ! preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $version)) throw new \RuntimeException('DEMO_PRICING_MISCONFIGURED');
        $base = intdiv($ridingMeters * (int) $rate + 999, 1000);
        $surcharge = $choice === 'DIRECT_RIDER_PICKUP' ? (int) $extra : 0;
        return ['currency' => 'INR', 'demo_only' => true, 'label' => 'Configurable demo pricing · no production tariff agreed',
            'policy_version' => $version, 'minor_per_km' => (int) $rate, 'riding_distance_meters' => $ridingMeters,
            'base_fare_minor' => $base, 'direct_pickup_surcharge_minor' => $surcharge,
            'driver_additional_compensation_minor' => $surcharge, 'total_fare_minor' => $base + $surcharge];
    }
}
