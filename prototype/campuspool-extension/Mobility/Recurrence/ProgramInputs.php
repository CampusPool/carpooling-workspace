<?php

namespace STS\Mobility\Recurrence;

use Carbon\CarbonImmutable;

final class ProgramInputs
{
    public static function integer(mixed $value, int $min, int $max, string $code = 'PROGRAM_INPUT_INVALID'): int
    {
        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new ProgramException($code);
        }

        return (int) $value;
    }

    public static function date(mixed $value, string $code = 'PROGRAM_DATE_INVALID'): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new ProgramException($code);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Kolkata');
        } catch (\Throwable) {
            throw new ProgramException($code);
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new ProgramException($code);
        }

        return $date;
    }

    public static function uuid(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $value)) {
            throw new ProgramException('PROGRAM_INPUT_INVALID');
        }

        return strtolower($value);
    }

    public static function time(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) {
            throw new ProgramException('PROGRAM_INPUT_INVALID');
        }

        return $value;
    }

    public static function text(mixed $value, int $maximum, bool $required = false): string
    {
        if (! is_string($value) || mb_strlen($value) > $maximum || ($required && trim($value) === '')) {
            throw new ProgramException('PROGRAM_INPUT_INVALID');
        }

        return $value;
    }

    public static function key(string $key): string
    {
        if (! preg_match('/^[\x21-\x7e]{8,100}$/D', $key)) {
            throw new ProgramException('IDEMPOTENCY_KEY_INVALID');
        }

        return $key;
    }

    public static function fields(array $input, array $required, array $optional = []): void
    {
        if (array_diff(array_keys($input), array_merge($required, $optional)) || array_diff($required, array_keys($input))) {
            throw new ProgramException('PROGRAM_INPUT_INVALID');
        }
    }

    public static function hash(array $input): string
    {
        ksort($input);
        foreach ($input as &$value) {
            if (is_array($value)) {
                ksort($value);
            }
        }

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
