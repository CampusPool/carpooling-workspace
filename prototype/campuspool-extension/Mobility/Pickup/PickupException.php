<?php

namespace STS\Mobility\Pickup;

final class PickupException extends \RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($machineCode);
    }
}
