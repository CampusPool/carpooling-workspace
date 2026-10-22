<?php

namespace STS\Mobility\Routing;

use RuntimeException;

final class RoutingException extends RuntimeException
{
    public function __construct(public readonly string $machineCode, string $message = '')
    {
        parent::__construct($message ?: $machineCode);
    }
}
