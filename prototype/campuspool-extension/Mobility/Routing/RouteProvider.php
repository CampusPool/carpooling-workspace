<?php

namespace STS\Mobility\Routing;

interface RouteProvider
{
    /** @return array{distance_meters:int,duration_seconds:int,provider:string,profile:string,data_version?:string} */
    public function route(array $pickup, array $dropoff, string $vehicleType): array;
}
