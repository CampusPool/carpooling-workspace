<?php

namespace STS\Mobility\Tracking;

final class TrackingException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 409)
    {
        parent::__construct($errorCode);
    }
}
