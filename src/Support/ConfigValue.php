<?php

namespace UBO\Common\Support;

/**
 * Reads one config value, falling back to a default when it is missing, empty or out of
 * range. An empty "UBO_X=" in the .env reads as "", so it never turns into 0.
 *
 * @internal
 */
final class ConfigValue
{
    /** A whole number above zero, or $default. */
    public static function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /** A whole number of zero or more, or $default. */
    public static function nonNegativeInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }

    /** A string that is not blank, or $default. */
    public static function filledString(mixed $value, string $default): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : $default;
    }
}
