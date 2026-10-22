<?php

namespace STS\Mobility\Routing;

final class OfferException extends \RuntimeException
{
    public function __construct(private string $codeName, private int $httpStatus)
    {
        parent::__construct($codeName);
    }

    public function getCodeName(): string
    {
        return $this->codeName;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
