<?php

namespace STS\Mobility\Dispatch;

final class DispatchInputs
{
    public static function fields(array $input, array $required, array $optional = []): void
    {
        if (array_diff(array_keys($input), array_merge($required, $optional)) || array_diff($required, array_keys($input))) {
            throw new DispatchException('UNKNOWN_FIELDS', 422);
        }
    }

    public static function id(mixed $value, string $code = 'INPUT_INVALID'): int
    {
        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new DispatchException($code, 422);
        }

        return (int) $value;
    }

    public static function uuid(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $value)) {
            throw new DispatchException('INPUT_INVALID', 422);
        }

        return strtolower($value);
    }

    public static function key(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[\x21-\x7e]{8,100}$/D', $value)) {
            throw new DispatchException('INVALID_IDEMPOTENCY_KEY', 422);
        }

        return $value;
    }

    public static function hash(array $payload): string
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                ksort($value);
            }
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
