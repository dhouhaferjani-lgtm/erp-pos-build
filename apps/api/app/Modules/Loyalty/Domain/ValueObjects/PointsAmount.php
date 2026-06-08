<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\ValueObjects;

use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;

/**
 * Value object representing a points amount with validation.
 *
 * Immutable. Internals are numeric-string + bcmath only — no float / IEEE-754
 * arithmetic and no tolerance/epsilon comparisons. Points are canonicalised to
 * the loyalty scale (3 decimal places), matching loyalty_transactions.amount
 * NUMERIC(15,3) and the FALLBACK_SCALE=3 used by the earning/processing services.
 */
final readonly class PointsAmount
{
    /**
     * ~1 billion points max (matches the historical float ceiling, expressed
     * as a numeric string so the check stays in bcmath).
     */
    private const MAX_POINTS = '999999999.999';

    /**
     * Canonical decimal scale for points. Aligns with the decimal(15,3) storage
     * columns and the TND-floor scale used across the Loyalty services.
     */
    public const SCALE = 3;

    /**
     * Canonical numeric string at SCALE decimals.
     *
     * @var numeric-string
     */
    public string $value;

    public function __construct(string $value)
    {
        $canonical = self::canonicalise($value);

        if (bccomp($canonical, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Points amount cannot be negative');
        }

        if (bccomp($canonical, self::MAX_POINTS, self::SCALE) > 0) {
            throw new InvalidArgumentException(
                sprintf('Points amount exceeds maximum allowed value of %s', self::MAX_POINTS)
            );
        }

        $this->value = $canonical;
    }

    /**
     * Canonicalise an arbitrary numeric string to SCALE decimals via bcmath.
     *
     * @return numeric-string
     */
    private static function canonicalise(string $value): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            throw new InvalidArgumentException(
                sprintf('Points amount must be a numeric string; "%s" given.', $value)
            );
        }

        return CurrencyScale::bcformatStrict($trimmed, self::SCALE);
    }

    /**
     * Create from a numeric value (float|int boundary).
     *
     * Use this only at float-ingress boundaries; prefer fromNumericString() when
     * the source is already a numeric string to avoid any float round-trip.
     */
    public static function fromNumeric(float|int $value): self
    {
        return new self(CurrencyScale::bcformat($value, self::SCALE));
    }

    /**
     * Create from a numeric string with no float round-trip.
     */
    public static function fromNumericString(string $value): self
    {
        return new self($value);
    }

    /**
     * Create zero points.
     */
    public static function zero(): self
    {
        return new self('0');
    }

    /**
     * Create one point.
     */
    public static function one(): self
    {
        return new self('1');
    }

    /**
     * Add points.
     */
    public function add(PointsAmount $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    /**
     * Subtract points.
     */
    public function subtract(PointsAmount $other): self
    {
        $result = bcsub($this->value, $other->value, self::SCALE);

        if (bccomp($result, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Cannot subtract more points than available');
        }

        return new self($result);
    }

    /**
     * Multiply points by a factor (numeric string).
     */
    public function multiply(string $multiplier): self
    {
        $multiplier = trim($multiplier);
        if (! is_numeric($multiplier)) {
            throw new InvalidArgumentException('Multiplier must be a numeric string');
        }

        if (bccomp($multiplier, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative');
        }

        // Multiply at extended precision, then canonicalise to SCALE.
        return new self(bcmul($this->value, $multiplier, self::SCALE + 4));
    }

    /**
     * Apply a percentage (numeric string, 0–100).
     */
    public function percentage(string $percent): self
    {
        $percent = trim($percent);
        if (! is_numeric($percent)) {
            throw new InvalidArgumentException('Percentage must be a numeric string');
        }

        if (bccomp($percent, '0', self::SCALE) < 0 || bccomp($percent, '100', self::SCALE) > 0) {
            throw new InvalidArgumentException('Percentage must be between 0 and 100');
        }

        $fraction = bcdiv($percent, '100', self::SCALE + 6);

        return new self(bcmul($this->value, $fraction, self::SCALE + 4));
    }

    /**
     * Round to whole number (half-up).
     */
    public function round(): self
    {
        return new self($this->bcRoundHalfUp($this->value));
    }

    /**
     * Round up to whole number.
     */
    public function ceil(): self
    {
        if ($this->hasFraction($this->value)) {
            $intPart = bcadd($this->value, '0', 0);

            return new self(bcadd($intPart, '1', 0));
        }

        return new self(bcadd($this->value, '0', 0));
    }

    /**
     * Round down to whole number.
     */
    public function floor(): self
    {
        return new self(bcadd($this->value, '0', 0));
    }

    /**
     * Get minimum of two amounts (bccomp).
     */
    public function min(PointsAmount $other): self
    {
        return bccomp($this->value, $other->value, self::SCALE) <= 0 ? $this : $other;
    }

    /**
     * Get maximum of two amounts (bccomp).
     */
    public function max(PointsAmount $other): self
    {
        return bccomp($this->value, $other->value, self::SCALE) >= 0 ? $this : $other;
    }

    /**
     * Check if amount is zero (bccomp, no tolerance).
     */
    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    /**
     * Check if amount is greater than another (bccomp).
     */
    public function isGreaterThan(PointsAmount $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) > 0;
    }

    /**
     * Check if amount is less than another (bccomp).
     */
    public function isLessThan(PointsAmount $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) < 0;
    }

    /**
     * Check if amount equals another (bccomp at canonical scale, no tolerance).
     */
    public function equals(PointsAmount $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) === 0;
    }

    /**
     * Format for display (thousands-separated, fixed decimals).
     */
    public function format(int $decimals = self::SCALE): string
    {
        // Display-only: number_format on a canonical string is precision-safe here
        // because the value already carries no float artefacts.
        return number_format((float) $this->value, $decimals);
    }

    /**
     * Convert to canonical numeric string.
     *
     * @return numeric-string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Convert to int (rounded half-up).
     */
    public function toInt(): int
    {
        return (int) $this->bcRoundHalfUp($this->value);
    }

    /**
     * bcmath half-up rounding to an integer string (handles only non-negative
     * values, which is guaranteed by the constructor invariant).
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function bcRoundHalfUp(string $value): string
    {
        if (! $this->hasFraction($value)) {
            return bcadd($value, '0', 0);
        }

        // Add 0.5 then truncate.
        return bcadd(bcadd($value, '0.5', self::SCALE + 1), '0', 0);
    }

    /**
     * @param  numeric-string  $value
     */
    private function hasFraction(string $value): bool
    {
        return bccomp($value, bcadd($value, '0', 0), self::SCALE) !== 0;
    }
}
