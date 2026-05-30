<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\ValueObjects;

use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;

/**
 * Value object representing a loyalty balance with current, earned, and redeemed
 * amounts.
 *
 * Immutable. Internals are numeric-string + bcmath only — no float / IEEE-754
 * arithmetic and no tolerance/epsilon comparisons. All amounts are canonicalised
 * to the loyalty scale (3 decimal places), matching the decimal(15,3) storage of
 * loyalty_enrollments.current_balance / lifetime_earned / lifetime_redeemed.
 *
 * The balance-integrity invariant (current === lifetimeEarned - lifetimeRedeemed)
 * is enforced exactly via bccomp at the canonical scale — the old code tolerated a
 * 0.01 epsilon, which let drift hide.
 */
final readonly class LoyaltyBalance
{
    /**
     * Canonical decimal scale for loyalty balances. Shared with PointsAmount so
     * balances and the points flowing into them agree on scale.
     */
    public const SCALE = PointsAmount::SCALE;

    /**
     * @var numeric-string
     */
    public string $current;

    /**
     * @var numeric-string
     */
    public string $lifetimeEarned;

    /**
     * @var numeric-string
     */
    public string $lifetimeRedeemed;

    public function __construct(
        string $current,
        string $lifetimeEarned,
        string $lifetimeRedeemed,
    ) {
        $current = self::canonicalise($current, 'current');
        $lifetimeEarned = self::canonicalise($lifetimeEarned, 'lifetimeEarned');
        $lifetimeRedeemed = self::canonicalise($lifetimeRedeemed, 'lifetimeRedeemed');

        if (bccomp($current, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Current balance cannot be negative');
        }

        if (bccomp($lifetimeEarned, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Lifetime earned cannot be negative');
        }

        if (bccomp($lifetimeRedeemed, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Lifetime redeemed cannot be negative');
        }

        // Exact balance-integrity check (no tolerance).
        $expected = bcsub($lifetimeEarned, $lifetimeRedeemed, self::SCALE);
        if (bccomp($current, $expected, self::SCALE) !== 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Balance integrity check failed: current=%s, earned=%s, redeemed=%s',
                    $current,
                    $lifetimeEarned,
                    $lifetimeRedeemed
                )
            );
        }

        $this->current = $current;
        $this->lifetimeEarned = $lifetimeEarned;
        $this->lifetimeRedeemed = $lifetimeRedeemed;
    }

    /**
     * Canonicalise an arbitrary numeric string to SCALE decimals via bcmath.
     *
     * @return numeric-string
     */
    private static function canonicalise(string $value, string $field): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            throw new InvalidArgumentException(
                sprintf('%s must be a numeric string; "%s" given.', $field, $value)
            );
        }

        return CurrencyScale::bcformatStrict($trimmed, self::SCALE);
    }

    /**
     * Create a zero balance.
     */
    public static function zero(): self
    {
        return new self('0', '0', '0');
    }

    /**
     * Create from enrollment data.
     */
    public static function fromEnrollment(
        string $current,
        string $lifetimeEarned,
        string $lifetimeRedeemed
    ): self {
        return new self($current, $lifetimeEarned, $lifetimeRedeemed);
    }

    /**
     * Add earned points.
     */
    public function addEarned(string $amount): self
    {
        $amount = self::canonicalise($amount, 'amount');

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Earned amount must be positive');
        }

        return new self(
            bcadd($this->current, $amount, self::SCALE),
            bcadd($this->lifetimeEarned, $amount, self::SCALE),
            $this->lifetimeRedeemed
        );
    }

    /**
     * Add redeemed points.
     */
    public function addRedeemed(string $amount): self
    {
        $amount = self::canonicalise($amount, 'amount');

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Redeemed amount must be positive');
        }

        if (bccomp($this->current, $amount, self::SCALE) < 0) {
            throw new InvalidArgumentException('Insufficient balance for redemption');
        }

        return new self(
            bcsub($this->current, $amount, self::SCALE),
            $this->lifetimeEarned,
            bcadd($this->lifetimeRedeemed, $amount, self::SCALE)
        );
    }

    /**
     * Check if there's sufficient balance for a redemption (bccomp).
     */
    public function hasSufficientBalance(string $amount): bool
    {
        $amount = self::canonicalise($amount, 'amount');

        return bccomp($this->current, $amount, self::SCALE) >= 0;
    }

    /**
     * Get balance after a hypothetical redemption (for previews), floored at 0.
     *
     * @return numeric-string
     */
    public function getBalanceAfterRedemption(string $amount): string
    {
        $amount = self::canonicalise($amount, 'amount');
        $result = bcsub($this->current, $amount, self::SCALE);

        if (bccomp($result, '0', self::SCALE) < 0) {
            return CurrencyScale::bcformatStrict('0', self::SCALE);
        }

        return $result;
    }

    /**
     * Check if balance is zero (bccomp, no tolerance).
     */
    public function isZero(): bool
    {
        return bccomp($this->current, '0', self::SCALE) === 0;
    }

    /**
     * Format current balance for display (thousands-separated, fixed decimals).
     */
    public function format(int $decimals = self::SCALE): string
    {
        return number_format((float) $this->current, $decimals);
    }

    /**
     * Convert to array of canonical numeric strings.
     *
     * @return array{current: numeric-string, lifetime_earned: numeric-string, lifetime_redeemed: numeric-string}
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
     * Check equality via bccomp at the canonical scale (no tolerance).
     */
    public function equals(LoyaltyBalance $other): bool
    {
        return bccomp($this->current, $other->current, self::SCALE) === 0
            && bccomp($this->lifetimeEarned, $other->lifetimeEarned, self::SCALE) === 0
            && bccomp($this->lifetimeRedeemed, $other->lifetimeRedeemed, self::SCALE) === 0;
    }
}
