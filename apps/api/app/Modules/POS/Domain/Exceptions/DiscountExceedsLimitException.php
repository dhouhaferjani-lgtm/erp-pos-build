<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

/**
 * Exception thrown when a discount exceeds the allowed limit.
 *
 * This occurs when:
 * - Cashier tries to apply discount > their max_discount_percent
 * - Cashier tries to apply discount > terminal's max_discount_percent
 * - The most restrictive limit (cashier vs terminal) is enforced
 */
class DiscountExceedsLimitException extends Exception
{
    /**
     * Create exception for cashier exceeding their personal limit
     */
    public static function forCashier(float $requestedPercent, float $cashierLimit, string $cashierId): self
    {
        return new self(
            sprintf(
                'Discount of %.2f%% exceeds cashier limit of %.2f%% (cashier: %s)',
                $requestedPercent,
                $cashierLimit,
                $cashierId
            )
        );
    }

    /**
     * Create exception for exceeding terminal limit
     */
    public static function forTerminal(float $requestedPercent, float $terminalLimit, string $terminalId): self
    {
        return new self(
            sprintf(
                'Discount of %.2f%% exceeds terminal limit of %.2f%% (terminal: %s)',
                $requestedPercent,
                $terminalLimit,
                $terminalId
            )
        );
    }

    /**
     * Create exception when discount exceeds the effective limit
     * (most restrictive between cashier and terminal)
     *
     * @param  numeric-string  $requestedPercent
     * @param  numeric-string  $effectiveLimit
     */
    public static function forEffectiveLimit(
        string $requestedPercent,
        string $effectiveLimit,
        string $limitSource
    ): self {
        return new self(
            sprintf(
                'Discount of %.2f%% exceeds effective limit of %.2f%% (limited by: %s)',
                (float) $requestedPercent,
                (float) $effectiveLimit,
                $limitSource
            )
        );
    }
}
