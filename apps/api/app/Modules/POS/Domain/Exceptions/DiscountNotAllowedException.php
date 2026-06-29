<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

/**
 * Exception thrown when a discount is not allowed.
 *
 * This occurs when:
 * - Cashier does not have discount permission (can_discount = false)
 * - Terminal has disabled line-level discounts
 * - Terminal has disabled transaction-level discounts
 * - Discount reason is missing when required (for high discounts)
 */
class DiscountNotAllowedException extends Exception
{
    /**
     * Create exception for cashier without discount permission
     */
    public static function cashierNotAuthorized(string $cashierId): self
    {
        return new self(
            sprintf(
                'Cashier %s is not authorized to apply discounts (can_discount = false)',
                $cashierId
            )
        );
    }

    /**
     * Create exception for line discounts disabled on terminal
     */
    public static function lineDiscountsDisabled(string $terminalId): self
    {
        return new self(
            sprintf(
                'Line-level discounts are disabled on terminal %s',
                $terminalId
            )
        );
    }

    /**
     * Create exception for transaction discounts disabled on terminal
     */
    public static function transactionDiscountsDisabled(string $terminalId): self
    {
        return new self(
            sprintf(
                'Transaction-level discounts are disabled on terminal %s',
                $terminalId
            )
        );
    }

    /**
     * Create exception when discount reason is required but missing
     *
     * @param  numeric-string  $discountPercent
     * @param  numeric-string  $threshold
     */
    public static function reasonRequired(string $discountPercent, string $threshold): self
    {
        return new self(
            sprintf(
                'Discount reason is required for discounts above %.2f%% (requested: %.2f%%)',
                (float) $threshold,
                (float) $discountPercent
            )
        );
    }
}
