<?php

namespace STS\Mobility\Routing;

final class RouteDataVersion
{
    public static function valid(mixed $value, string $vehicleType): bool
    {
        return is_string($value) && preg_match('/^(car|bike)\|crop_sha256=[a-f0-9]{64}\|profile_sha256=[a-f0-9]{64}$/D', $value, $matches) === 1 && ($matches[1] ?? null) === $vehicleType;
    }

    public static function allowed(mixed $value, string $vehicleType, bool $synthetic): bool
    {
        return $value === null ? $synthetic : self::valid($value, $vehicleType);
    }
}
