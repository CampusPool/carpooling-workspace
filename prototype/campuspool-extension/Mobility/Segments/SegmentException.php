<?php

namespace STS\Mobility\Segments;

final class SegmentException extends \RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $httpStatus = 409)
    {
        parent::__construct($machineCode);
    }
}
