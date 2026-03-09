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
     * Threshold percentage requiring a discount reason
     */
    private const REASON_REQUIRED_THRESHOLD = 10.00;

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
     * @param  float  $discountPercent  The discount percentage being requested
     * @param  string|null  $reason  Reason for discount (required for high discounts)
     *
     * @throws DiscountNotAllowedException If discount is not allowed
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    public function validateLineDiscount(
        Terminal $terminal,
        User $cashier,
        float $discountPercent,
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
        $this->validateDiscountLimit($terminal, $cashier, (float) $discountPercent);

        // Check if reason is required
        $this->validateDiscountReason((float) $discountPercent, $reason);
    }

    /**
     * Calculate discount amount from percentage
     *
     * Uses bcmath for precise decimal calculations.
     *
     * @param  numeric-string  $baseAmount  The base amount to calculate discount on (decimal string)
     * @param  float  $discountPercent  The discount percentage
     * @return numeric-string The calculated discount amount (decimal string)
     */
    public function calculateLineDiscountAmount(string $baseAmount, float $discountPercent): string
    {
        // Convert percentage to decimal (e.g., 10% = 0.10)
        /** @var numeric-string $discountRate */
        $discountRate = bcdiv((string) $discountPercent, '100', 10);

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
                    'Fixed discount %.2f cannot exceed base amount %.2f',
                    (float) $fixedDiscount,
                    (float) $baseAmount
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
     * @return array{limit: float, source: string} The effective limit and its source
     */
    public function getEffectiveDiscountLimit(Terminal $terminal, User $cashier): array
    {
        $terminalLimit = (float) $terminal->max_discount_percent;
        $cashierLimit = $cashier->max_discount_percent !== null
            ? (float) $cashier->max_discount_percent
            : PHP_FLOAT_MAX;

        // Most restrictive limit wins
        if ($terminalLimit <= $cashierLimit) {
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
     * @param  float  $discountPercent  Requested discount percentage
     *
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    private function validateDiscountLimit(
        Terminal $terminal,
        User $cashier,
        float $discountPercent
    ): void {
        $effectiveLimit = $this->getEffectiveDiscountLimit($terminal, $cashier);

        if ($discountPercent > $effectiveLimit['limit']) {
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
     * @param  float  $discountPercent  The discount percentage
     * @param  string|null  $reason  The provided reason
     *
     * @throws DiscountNotAllowedException If reason is required but missing
     */
    private function validateDiscountReason(float $discountPercent, ?string $reason): void
    {
        if ($discountPercent > self::REASON_REQUIRED_THRESHOLD && ($reason === null || trim($reason) === '')) {
            throw DiscountNotAllowedException::reasonRequired(
                $discountPercent,
                self::REASON_REQUIRED_THRESHOLD
            );
        }
    }
}
