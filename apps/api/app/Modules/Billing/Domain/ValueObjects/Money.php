<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\ValueObjects;

use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;

/**
 * Immutable Money value object.
 *
 * Stores the amount as a normalised numeric-string at the currency's ISO 4217
 * decimal scale and performs all arithmetic with bcmath. No IEEE-754 floats are
 * ever used internally, so 0.1 + 0.2 === 0.30 exactly and equality is an exact
 * bccomp (no epsilon tolerance).
 */
final readonly class Money
{
    /**
     * @var numeric-string Amount normalised to the currency scale.
     */
    public string $amount;

    /**
     * @param  string  $amount  A numeric-string amount (e.g. "12.34"). Float input is not accepted.
     */
    public function __construct(
        string $amount,
        public string $currency,
    ) {
        $scale = CurrencyScale::for($this->currency);

        // Normalises and validates the string is numeric (throws InvalidArgumentException otherwise).
        $normalized = CurrencyScale::bcformatStrict($amount, $scale);

        if (bccomp($normalized, '0', $scale) < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }

        $this->amount = $normalized;
    }

    /**
     * Create from integer minor units (e.g. Stripe cents) for the currency scale.
     */
    public static function fromCents(int $cents, string $currency): self
    {
        $scale = CurrencyScale::for($currency);
        $divisor = bcpow('10', (string) $scale);

        return new self(bcdiv((string) $cents, $divisor, $scale), $currency);
    }

    /**
     * Convert to integer minor units (e.g. Stripe cents) for the currency scale.
     *
     * For 2-decimal currencies this is amount × 100; for TND (3) it is × 1000;
     * for JPY (0) it is × 1.
     */
    public function toCents(): int
    {
        $scale = CurrencyScale::for($this->currency);
        $multiplier = bcpow('10', (string) $scale);

        // Result is an integer-valued string (scale 0); safe to cast to int.
        return (int) bcmul($this->amount, $multiplier, 0);
    }

    /**
     * Add another money amount.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        $scale = CurrencyScale::for($this->currency);

        return new self(bcadd($this->amount, $other->amount, $scale), $this->currency);
    }

    /**
     * Subtract another money amount.
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $scale = CurrencyScale::for($this->currency);
        $result = bcsub($this->amount, $other->amount, $scale);

        if (bccomp($result, '0', $scale) < 0) {
            throw new InvalidArgumentException('Result cannot be negative');
        }

        return new self($result, $this->currency);
    }

    /**
     * Multiply by a numeric-string factor.
     *
     * The product is computed at scale+4 precision, then normalised once to the
     * currency scale at the boundary. Normalisation TRUNCATES toward zero (via
     * CurrencyScale::bcformat) — consistent with every other write boundary in
     * the precision contract. A future caller needing half-up rounding (e.g.
     * proration billing) must round explicitly before constructing the Money.
     */
    public function multiply(string $factor): self
    {
        if (! is_numeric($factor)) {
            throw new InvalidArgumentException(
                sprintf('Money::multiply() expects a numeric string; "%s" given.', $factor),
            );
        }

        $scale = CurrencyScale::for($this->currency);

        // Compute at extra precision, then normalise once to currency scale.
        $product = bcmul($this->amount, $factor, $scale + 4);

        return new self($product, $this->currency);
    }

    /**
     * Check if this amount is less than another.
     */
    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, CurrencyScale::for($this->currency)) < 0;
    }

    /**
     * Check if this amount is greater than another.
     */
    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, CurrencyScale::for($this->currency)) > 0;
    }

    /**
     * Check if this amount equals another (exact comparison at currency scale).
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, CurrencyScale::for($this->currency)) === 0;
    }

    /**
     * Check if amount is zero.
     */
    public function isZero(): bool
    {
        return bccomp($this->amount, '0', CurrencyScale::for($this->currency)) === 0;
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
            'JPY' => "\u{00A5}",
            'TND' => 'TND',
            'MAD' => 'MAD',
            'DZD' => 'DZD',
            default => $this->currency,
        };

        return $symbol.$this->amount;
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
