<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable Money value object.
 */
final readonly class Money
{
    public function __construct(
        public float $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }
    }

    /**
     * Create from cents (for Stripe compatibility).
     */
    public static function fromCents(int $cents, string $currency): self
    {
        return new self($cents / 100, $currency);
    }

    /**
     * Convert to cents (for Stripe API).
     */
    public function toCents(): int
    {
        return (int) round($this->amount * 100);
    }

    /**
     * Add another money amount.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract another money amount.
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = $this->amount - $other->amount;
        if ($result < 0) {
            throw new InvalidArgumentException('Result cannot be negative');
        }

        return new self($result, $this->currency);
    }

    /**
     * Multiply by a factor.
     */
    public function multiply(float $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Check if this amount is less than another.
     */
    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    /**
     * Check if this amount is greater than another.
     */
    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    /**
     * Check if this amount equals another.
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && abs($this->amount - $other->amount) < 0.001;
    }

    /**
     * Check if amount is zero.
     */
    public function isZero(): bool
    {
        return abs($this->amount) < 0.001;
    }

    /**
     * Format for display.
     */
    public function format(): string
    {
        $symbol = match ($this->currency) {
            'EUR' => "\u{20AC}",
            'USD' => '$',
            'GBP' => "\u{00A3}",
            'TND' => 'TND',
            'MAD' => 'MAD',
            'DZD' => 'DZD',
            default => $this->currency,
        };

        return sprintf('%s%.2f', $symbol, $this->amount);
    }

    /**
     * Assert that both money objects have the same currency.
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on different currencies: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
