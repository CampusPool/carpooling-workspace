<?php

namespace STS\Mobility;

final class CashReceiptException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
