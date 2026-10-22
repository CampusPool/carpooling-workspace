<?php

namespace STS\Mobility\Pickup;

interface PathProvider
{
    /**
     * @param list<array{lat: float|int, lng: float|int}> $points
     * @return array<string, mixed>
     */
    public function path(array $points, string $mode, float $timeoutSeconds = 5): array;
}
