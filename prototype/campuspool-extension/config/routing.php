<?php

return [
    // Separate prepared datasets/profiles are required for cars and motorbikes.
    // OSRM's supplied bicycle profile is not evidence of motorbike suitability.
    'provider' => env('MOBILITY_ROUTING_PROVIDER'),
    'car_base_url' => env('MOBILITY_ROUTING_CAR_BASE_URL'),
    'bike_base_url' => env('MOBILITY_ROUTING_BIKE_BASE_URL'),
    'car_profile' => env('MOBILITY_ROUTING_CAR_PROFILE'),
    'bike_profile' => env('MOBILITY_ROUTING_BIKE_PROFILE'),
    // A structured, mode-specific graph pin. The OSRM URL/profile label alone
    // does not select or identify the prepared graph.
    'car_expected_data_version' => env('MOBILITY_ROUTING_CAR_EXPECTED_DATA_VERSION'),
    'bike_expected_data_version' => env('MOBILITY_ROUTING_BIKE_EXPECTED_DATA_VERSION'),
    'foot_base_url' => env('MOBILITY_ROUTING_FOOT_BASE_URL'),
    'foot_profile' => env('MOBILITY_ROUTING_FOOT_PROFILE'),
    'foot_expected_data_version' => env('MOBILITY_ROUTING_FOOT_EXPECTED_DATA_VERSION'),
    'pricing_version' => env('MOBILITY_ROUTING_PRICING_VERSION'),
    'car_minor_per_km' => env('MOBILITY_ROUTING_CAR_MINOR_PER_KM'),
    'bike_minor_per_km' => env('MOBILITY_ROUTING_BIKE_MINOR_PER_KM'),
    // Explicit configurable demonstration fares, never an approved operating tariff.
    'demo_pricing_version' => env('MOBILITY_DEMO_PRICING_VERSION', 'demo-v1'),
    'demo_car_minor_per_km' => env('MOBILITY_DEMO_CAR_MINOR_PER_KM', 800),
    'demo_bike_minor_per_km' => env('MOBILITY_DEMO_BIKE_MINOR_PER_KM', 400),
    'demo_car_direct_surcharge_minor' => env('MOBILITY_DEMO_CAR_DIRECT_SURCHARGE_MINOR', 2000),
    'demo_bike_direct_surcharge_minor' => env('MOBILITY_DEMO_BIKE_DIRECT_SURCHARGE_MINOR', 1000),
    // Configurable demo scheduling allowance on each side of a planned route.
    'driver_reservation_buffer_seconds' => env('MOBILITY_DRIVER_RESERVATION_BUFFER_SECONDS', 300),
    'synthetic_only' => env('MOBILITY_ROUTING_SYNTHETIC_ONLY', false),
];
