<?php

namespace STS\Mobility\Payments;

use RuntimeException;

final class PaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly string $codeName = 'PAYMENT_REJECTED')
    {
        parent::__construct($message);
    }
}
