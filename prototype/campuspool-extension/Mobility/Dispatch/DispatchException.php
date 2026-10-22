<?php

namespace STS\Mobility\Dispatch;

use RuntimeException;

final class DispatchException extends RuntimeException
{
    public function __construct(public readonly string $machineCode, public readonly int $httpStatus = 409, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $machineCode);
    }
}
