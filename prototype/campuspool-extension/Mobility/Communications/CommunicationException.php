<?php

namespace STS\Mobility\Communications;

final class CommunicationException extends \RuntimeException
{
    public function __construct(public string $machineCode, public int $httpStatus = 409)
    { parent::__construct($machineCode); }
}
