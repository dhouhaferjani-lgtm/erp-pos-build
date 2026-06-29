<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Domain service for discount calculation and validation.
 *
 * Handles validation and calculation of line-level and transaction-level discounts
 * with enforcement of terminal and cashier limits.
 *
 * Business Rules:
 * - Cashier must have can_discount = true
 * - Most restrictive limit applies (terminal vs cashier)
 * - Discount reason required for discounts > 10%
 * - Line discounts can be disabled per terminal
 * - Transaction discounts can be disabled per terminal
 */
final readonly class DiscountCalculationService
{
    /**
     * Threshold percentage requiring a discount reason (numeric-string, percent rate)
     */
    private const string REASON_REQUIRED_THRESHOLD = '10.00';

    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Validate if a line-level discount is allowed
     *
     * @param  Terminal  $terminal  The terminal where discount is being applied
     * @param  User  $cashier  The cashier applying the discount
     * @param  numeric-string  $discountPercent  The discount percentage being requested
     * @param  string|null  $reason  Reason for discount (required for high discounts)
     *
     * @throws DiscountNotAllowedException If discount is not allowed
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    public function validateLineDiscount(
        Terminal $terminal,
        User $cashier,
        string $discountPercent,
        ?string $reason = null
    ): void {
        // Check if cashier has discount permission
        if (! $cashier->can_discount) {
            throw DiscountNotAllowedException::cashierNotAuthorized((string) $cashier->id);
        }

        // Check if terminal allows line discounts
        if (! $terminal->allow_line_discounts) {
            throw DiscountNotAllowedException::lineDiscountsDisabled((string) $terminal->id);
        }

        // Validate discount amount against limits
        $this->validateDiscountLimit($terminal, $cashier, $discountPercent);

        // Check if reason is required
        $this->validateDiscountReason($discountPercent, $reason);
    }

    /**
     * Validate if a transaction-level discount is allowed
     *
     * @param  Terminal  $terminal  The terminal where discount is being applied
     * @param  User  $cashier  The cashier applying the discount
     * @param  numeric-string  $subtotal  The subtotal to calculate discount percentage
     * @param  numeric-string  $discountAmount  The fixed discount amount being requested
     * @param  string|null  $reason  Reason for discount (required for high discounts)
     *
     * @throws DiscountNotAllowedException If discount is not allowed
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    public function validateTransactionDiscount(
        Terminal $terminal,
        User $cashier,
        string $subtotal,
        string $discountAmount,
        ?string $reason = null
    ): void {
        // Check if cashier has discount permission
        if (! $cashier->can_discount) {
            throw DiscountNotAllowedException::cashierNotAuthorized((string) $cashier->id);
        }

        // Check if terminal allows transaction discounts
        if (! $terminal->allow_transaction_discounts) {
            throw DiscountNotAllowedException::transactionDiscountsDisabled((string) $terminal->id);
        }

        // Validate subtotal is positive
        if (bccomp($subtotal, '0', 3) <= 0) {
            throw new \InvalidArgumentException('Subtotal must be greater than zero');
        }

        // Validate discount doesn't exceed subtotal
        if (bccomp($discountAmount, $subtotal, 3) > 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Discount amount %s cannot exceed subtotal %s',
                    $discountAmount,
                    $subtotal
                )
            );
        }

        /** @var numeric-string $discountPercent */
        $discountPercent = bcdiv(
            bcmul($discountAmount, '100', 10),
            $subtotal,
            2
        );

        // Validate discount amount against limits
        $this->validateDiscountLimit($terminal, $cashier, $discountPercent);

        // Check if reason is required
        $this->validateDiscountReason($discountPercent, $reason);
    }

    /**
     * Calculate discount amount from percentage
     *
     * Uses bcmath for precise decimal calculations.
     *
     * @param  numeric-string  $baseAmount  The base amount to calculate discount on (decimal string)
     * @param  numeric-string  $discountPercent  The discount percentage as a numeric string (e.g. '10.05')
     * @return numeric-string The calculated discount amount (decimal string)
     */
    public function calculateLineDiscountAmount(string $baseAmount, string $discountPercent): string
    {
        // Convert percentage to decimal (e.g., 10% = 0.10)
        /** @var numeric-string $discountRate */
        $discountRate = bcdiv($discountPercent, '100', 10);

        // Calculate discount amount
        /** @var numeric-string $discountAmount */
        $discountAmount = bcmul($baseAmount, $discountRate, 10);

        // Round to 2 decimal places
        /** @var numeric-string $result */
        $result = bcadd($discountAmount, '0', $this->scale());

        return $result;
    }

    /**
     * Calculate discount amount from fixed value
     *
     * Validates that fixed discount doesn't exceed base amount.
     *
     * @param  numeric-string  $baseAmount  The base amount (decimal string)
     * @param  numeric-string  $fixedDiscount  The fixed discount amount (decimal string)
     * @return numeric-string The validated discount amount (decimal string)
     *
     * @throws \InvalidArgumentException If discount exceeds base amount
     */
    public function calculateFixedDiscountAmount(string $baseAmount, string $fixedDiscount): string
    {
        // Ensure discount doesn't exceed base amount
        if (bccomp($fixedDiscount, $baseAmount, $this->scale()) > 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Fixed discount %s cannot exceed base amount %s',
                    $fixedDiscount,
                    $baseAmount
                )
            );
        }

        /** @var numeric-string $result */
        $result = bcadd($fixedDiscount, '0', $this->scale());

        return $result;
    }

    /**
     * Get effective discount limit (most restrictive between terminal and cashier)
     *
     * @param  Terminal  $terminal  The terminal
     * @param  User  $cashier  The cashier
     * @return array{limit: numeric-string, source: string} The effective limit and its source
     */
    public function getEffectiveDiscountLimit(Terminal $terminal, User $cashier): array
    {
        /** @var numeric-string $terminalLimit */
        $terminalLimit = (string) $terminal->max_discount_percent;

        $cashierMaxPercent = $cashier->max_discount_percent;

        // No individual cashier limit — terminal limit applies
        if ($cashierMaxPercent === null) {
            return [
                'limit' => $terminalLimit,
                'source' => 'terminal',
            ];
        }

        /** @var numeric-string $cashierLimit */
        $cashierLimit = (string) $cashierMaxPercent;

        // Most restrictive limit wins (lower value = more restrictive)
        if (bccomp($terminalLimit, $cashierLimit, 2) <= 0) { // precision-ok: percent rate — 2 dp is the stored column precision (decimal:2), currency-independent
            return [
                'limit' => $terminalLimit,
                'source' => 'terminal',
            ];
        }

        return [
            'limit' => $cashierLimit,
            'source' => 'cashier',
        ];
    }

    /**
     * Validate discount against terminal and cashier limits
     *
     * @param  Terminal  $terminal  The terminal
     * @param  User  $cashier  The cashier
     * @param  numeric-string  $discountPercent  Requested discount percentage
     *
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    private function validateDiscountLimit(
        Terminal $terminal,
        User $cashier,
        string $discountPercent
    ): void {
        $effectiveLimit = $this->getEffectiveDiscountLimit($terminal, $cashier);

        if (bccomp($discountPercent, $effectiveLimit['limit'], 2) > 0) { // precision-ok: percent rate — 2 dp matches decimal:2 column, currency-independent
            throw DiscountExceedsLimitException::forEffectiveLimit(
                $discountPercent,
                $effectiveLimit['limit'],
                $effectiveLimit['source']
            );
        }
    }

    /**
     * Validate that discount reason is provided when required
     *
     * @param  numeric-string  $discountPercent  The discount percentage
     * @param  string|null  $reason  The provided reason
     *
     * @throws DiscountNotAllowedException If reason is required but missing
     */
    private function validateDiscountReason(string $discountPercent, ?string $reason): void
    {
        if (bccomp($discountPercent, self::REASON_REQUIRED_THRESHOLD, 2) > 0 && ($reason === null || trim($reason) === '')) { // precision-ok: percent rate — 2 dp matches column precision, currency-independent
            throw DiscountNotAllowedException::reasonRequired(
                $discountPercent,
                self::REASON_REQUIRED_THRESHOLD
            );
        }
    }
}
