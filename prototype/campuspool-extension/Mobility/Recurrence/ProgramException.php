<?php

namespace STS\Mobility\Recurrence;

final class ProgramException extends \RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($machineCode);
    }
}
