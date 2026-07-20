<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Bcmath-native rounding helpers for quantity values.
 *
 * All methods stay in string/bcmath throughout — no float intermediaries.
 * This eliminates IEEE 754 precision loss for extreme quantities (e.g. values
 * near the 10^15 boundary where (float) cast truncates sub-unit digits).
 */
final class QuantityScale
{
    /**
     * Canonical storage scale for quantities (decimal(N,4) - precision contract).
     * Quantity scale is a domain constant, not currency-dependent.
     */
    public const int SCALE = 4;

    /** Supported rounding method identifiers. */
    public const HALF_UP = 'half_up';

    public const FLOOR = 'floor';

    public const CEIL = 'ceil';

    /**
     * Round a numeric string to $decimalPlaces using bcmath (no float).
     *
     * @param  string  $value  Well-formed numeric string (e.g. "1.23456")
     * @param  int  $decimalPlaces  Target decimal scale (>= 0)
     * @param  string  $method  One of 'half_up', 'floor', 'ceil'
     * @return numeric-string Rounded numeric string with exactly $decimalPlaces digits
     *
     * @throws \InvalidArgumentException For unknown rounding method
     */
    public static function round(string $value, int $decimalPlaces, string $method): string
    {
        /** @var numeric-string $numericValue */
        $numericValue = $value;
        /** @var numeric-string $multiplier */
        $multiplier = bcpow('10', (string) $decimalPlaces, 0);

        // Scale up: move the decimal point right by $decimalPlaces.
        // Use extra precision so we can see the first digit that will be rounded.
        /** @var numeric-string $scaled */
        $scaled = bcmul($numericValue, $multiplier, 10);

        // Extract the integer part and the first fractional digit after scaling.
        $rounded = match ($method) {
            self::HALF_UP => self::bcRoundHalfUp($scaled),
            self::FLOOR => self::bcFloor($scaled),
            self::CEIL => self::bcCeil($scaled),
            default => throw new \InvalidArgumentException(
                sprintf('Unknown rounding method "%s". Expected one of: half_up, floor, ceil.', $method),
            ),
        };

        return bcdiv($rounded, $multiplier, $decimalPlaces);
    }

    /**
     * Format a canonical scale-4 quantity for human display at a unit's precision.
     * Primitives only (Shared must not depend on module entities): callers pass
     * $unit?->decimal_places and $unit?->rounding_method?->value.
     *
     * @param  numeric-string  $value
     * @return numeric-string exactly $decimalPlaces digits (0 => integer string)
     */
    public static function formatForUnit(string $value, ?int $decimalPlaces, ?string $roundingMethod = null): string
    {
        return self::round($value, $decimalPlaces ?? self::SCALE, $roundingMethod ?? self::HALF_UP);
    }

    /**
     * HalfUp: if the fractional part of $scaledValue is >= 0.5, round away from zero.
     *
     * @param  numeric-string  $scaledValue  Already-scaled numeric string (may have decimals)
     * @return numeric-string Integer string
     */
    private static function bcRoundHalfUp(string $scaledValue): string
    {
        // For positive values: add 0.5 then truncate toward zero.
        // For negative values: subtract 0.5 then truncate toward zero.
        if (bccomp($scaledValue, '0', 10) >= 0) {
            /** @var numeric-string $bumped */
            $bumped = bcadd($scaledValue, '0.5', 10);
        } else {
            /** @var numeric-string $bumped */
            $bumped = bcsub($scaledValue, '0.5', 10);
        }

        // Truncate to integer (scale 0).
        /** @var numeric-string */
        return bcadd($bumped, '0', 0);
    }

    /**
     * Floor: largest integer <= $scaledValue (toward negative infinity).
     *
     * @param  numeric-string  $scaledValue  Already-scaled numeric string
     * @return numeric-string Integer string
     */
    private static function bcFloor(string $scaledValue): string
    {
        // bcadd with scale=0 truncates toward zero (not floor for negatives).
        /** @var numeric-string $truncated */
        $truncated = bcadd($scaledValue, '0', 0);

        // If the original was negative and had a fractional part, subtract 1.
        if (
            bccomp($scaledValue, '0', 10) < 0
            && bccomp($scaledValue, $truncated, 10) !== 0
        ) {
            /** @var numeric-string */
            return bcsub($truncated, '1', 0);
        }

        return $truncated;
    }

    /**
     * Ceil: smallest integer >= $scaledValue (toward positive infinity).
     *
     * @param  numeric-string  $scaledValue  Already-scaled numeric string
     * @return numeric-string Integer string
     */
    private static function bcCeil(string $scaledValue): string
    {
        // Truncate toward zero first.
        /** @var numeric-string $truncated */
        $truncated = bcadd($scaledValue, '0', 0);

        // If the original was positive and had a fractional part, add 1.
        if (
            bccomp($scaledValue, '0', 10) > 0
            && bccomp($scaledValue, $truncated, 10) !== 0
        ) {
            /** @var numeric-string */
            return bcadd($truncated, '1', 0);
        }

        return $truncated;
    }
}
