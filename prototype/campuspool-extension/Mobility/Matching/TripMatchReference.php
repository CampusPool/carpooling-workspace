<?php

namespace STS\Mobility\Matching;

use InvalidArgumentException;

/**
 * A client-visible reference to an independently stored match decision.
 *
 * Identifiers are intentionally opaque, matching the four named request fields
 * observed through REA. This is our own validated contract, not HOPR source.
 */
final class TripMatchReference
{
    public function __construct(
        public readonly string $tripMatchId,
        public readonly string $tripId,
        public readonly string $roleType,
        public readonly string $date,
    ) {
        foreach (['tripMatchId' => $tripMatchId, 'tripId' => $tripId] as $key => $value) {
            if (strlen($value) < 1 || strlen($value) > 128
                || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $value)) {
                throw new InvalidArgumentException("Invalid {$key}.");
            }
        }
        if (strlen($roleType) < 1 || strlen($roleType) > 32
            || !preg_match('/^[A-Za-z0-9_]+$/D', $roleType)) {
            throw new InvalidArgumentException('Invalid roleType.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new InvalidArgumentException('Invalid match date.');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            throw new InvalidArgumentException('Invalid match date.');
        }
    }

    /** @return array{tripMatchId:string,tripId:string,roleType:string,date:string} */
    public function asArray(): array
    {
        return ['tripMatchId' => $this->tripMatchId, 'tripId' => $this->tripId,
            'roleType' => $this->roleType, 'date' => $this->date];
    }
}
