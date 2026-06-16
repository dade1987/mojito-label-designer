<?php

declare(strict_types=1);

namespace Mojito\Label;

use InvalidArgumentException;

final class TypeCaster
{
    /**
     * @phpstan-assert array<string, mixed> $value
     */
    public static function assertStringKeyedArray(mixed $value): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Valore array atteso.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringKeyedArray(mixed $value): array
    {
        self::assertStringKeyedArray($value);

        return $value;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $default;
    }
}
