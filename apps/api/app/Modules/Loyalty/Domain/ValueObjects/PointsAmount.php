<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Value object representing a points amount with validation
 *
 * Immutable - ensures points are always positive and properly formatted
 */
final readonly class PointsAmount
{
    private const MAX_POINTS = 999999999.99; // ~1 billion points max

    private const PRECISION = 2; // Decimal places

    public function __construct(
        public float $value
    ) {
        if ($value < 0) {
            throw new InvalidArgumentException('Points amount cannot be negative');
        }

        if ($value > self::MAX_POINTS) {
            throw new InvalidArgumentException(
                sprintf('Points amount exceeds maximum allowed value of %.2f', self::MAX_POINTS)
            );
        }

        // Validate precision (max 2 decimal places)
        $rounded = round($value, self::PRECISION);
        if (abs($value - $rounded) > 0.001) {
            throw new InvalidArgumentException(
                sprintf('Points amount must have at most %d decimal places', self::PRECISION)
            );
        }
    }

    /**
     * Create from numeric value
     */
    public static function fromNumeric(float|int $value): self
    {
        return new self((float) $value);
    }

    /**
     * Create zero points
     */
    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * Create one point
     */
    public static function one(): self
    {
        return new self(1.0);
    }

    /**
     * Add points
     */
    public function add(PointsAmount $other): self
    {
        return new self($this->value + $other->value);
    }

    /**
     * Subtract points
     */
    public function subtract(PointsAmount $other): self
    {
        $result = $this->value - $other->value;

        if ($result < 0) {
            throw new InvalidArgumentException('Cannot subtract more points than available');
        }

        return new self($result);
    }

    /**
     * Multiply points by a factor
     */
    public function multiply(float $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative');
        }

        return new self($this->value * $multiplier);
    }

    /**
     * Apply a percentage
     */
    public function percentage(float $percent): self
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Percentage must be between 0 and 100');
        }

        return new self($this->value * ($percent / 100));
    }

    /**
     * Round to whole number
     */
    public function round(): self
    {
        return new self(round($this->value));
    }

    /**
     * Round up to whole number
     */
    public function ceil(): self
    {
        return new self(ceil($this->value));
    }

    /**
     * Round down to whole number
     */
    public function floor(): self
    {
        return new self(floor($this->value));
    }

    /**
     * Get minimum of two amounts
     */
    public function min(PointsAmount $other): self
    {
        return new self(min($this->value, $other->value));
    }

    /**
     * Get maximum of two amounts
     */
    public function max(PointsAmount $other): self
    {
        return new self(max($this->value, $other->value));
    }

    /**
     * Check if amount is zero
     */
    public function isZero(): bool
    {
        return abs($this->value) < 0.001;
    }

    /**
     * Check if amount is greater than another
     */
    public function isGreaterThan(PointsAmount $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Check if amount is less than another
     */
    public function isLessThan(PointsAmount $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Check if amount equals another
     */
    public function equals(PointsAmount $other): bool
    {
        return abs($this->value - $other->value) < 0.001;
    }

    /**
     * Format for display
     */
    public function format(int $decimals = 2): string
    {
        return number_format($this->value, $decimals);
    }

    /**
     * Convert to string
     */
    public function toString(): string
    {
        return (string) $this->value;
    }

    /**
     * Convert to int (rounded)
     */
    public function toInt(): int
    {
        return (int) round($this->value);
    }
}
