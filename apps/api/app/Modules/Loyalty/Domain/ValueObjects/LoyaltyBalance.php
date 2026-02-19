<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Value object representing a loyalty balance with current, earned, and redeemed amounts
 *
 * Immutable - all operations return new instances
 */
final readonly class LoyaltyBalance
{
    public function __construct(
        public float $current,
        public float $lifetimeEarned,
        public float $lifetimeRedeemed,
    ) {
        if ($current < 0) {
            throw new InvalidArgumentException('Current balance cannot be negative');
        }

        if ($lifetimeEarned < 0) {
            throw new InvalidArgumentException('Lifetime earned cannot be negative');
        }

        if ($lifetimeRedeemed < 0) {
            throw new InvalidArgumentException('Lifetime redeemed cannot be negative');
        }

        // Validate balance integrity
        $expected = $lifetimeEarned - $lifetimeRedeemed;
        if (abs($current - $expected) > 0.01) { // Allow for small floating point errors
            throw new InvalidArgumentException(
                sprintf(
                    'Balance integrity check failed: current=%.2f, earned=%.2f, redeemed=%.2f',
                    $current,
                    $lifetimeEarned,
                    $lifetimeRedeemed
                )
            );
        }
    }

    /**
     * Create a zero balance
     */
    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    /**
     * Create from enrollment data
     */
    public static function fromEnrollment(
        float $current,
        float $lifetimeEarned,
        float $lifetimeRedeemed
    ): self {
        return new self($current, $lifetimeEarned, $lifetimeRedeemed);
    }

    /**
     * Add earned points
     */
    public function addEarned(float $amount): self
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Earned amount must be positive');
        }

        return new self(
            $this->current + $amount,
            $this->lifetimeEarned + $amount,
            $this->lifetimeRedeemed
        );
    }

    /**
     * Add redeemed points
     */
    public function addRedeemed(float $amount): self
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Redeemed amount must be positive');
        }

        if ($this->current < $amount) {
            throw new InvalidArgumentException('Insufficient balance for redemption');
        }

        return new self(
            $this->current - $amount,
            $this->lifetimeEarned,
            $this->lifetimeRedeemed + $amount
        );
    }

    /**
     * Check if there's sufficient balance for a redemption
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->current >= $amount;
    }

    /**
     * Get balance after hypothetical redemption (for previews)
     */
    public function getBalanceAfterRedemption(float $amount): float
    {
        return max(0, $this->current - $amount);
    }

    /**
     * Check if balance is zero
     */
    public function isZero(): bool
    {
        return abs($this->current) < 0.01;
    }

    /**
     * Format balance for display
     */
    public function format(int $decimals = 2): string
    {
        return number_format($this->current, $decimals);
    }

    /**
     * Convert to array
     *
     * @return array{current: float, lifetime_earned: float, lifetime_redeemed: float}
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'lifetime_earned' => $this->lifetimeEarned,
            'lifetime_redeemed' => $this->lifetimeRedeemed,
        ];
    }

    /**
     * Check equality
     */
    public function equals(LoyaltyBalance $other): bool
    {
        return abs($this->current - $other->current) < 0.01
            && abs($this->lifetimeEarned - $other->lifetimeEarned) < 0.01
            && abs($this->lifetimeRedeemed - $other->lifetimeRedeemed) < 0.01;
    }
}
