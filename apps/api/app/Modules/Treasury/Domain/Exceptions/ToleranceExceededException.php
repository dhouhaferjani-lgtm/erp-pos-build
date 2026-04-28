<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when an invoice's remaining balance is outside the configured
 * payment-tolerance threshold for its company.
 *
 * The remaining-balance and the active threshold values are exposed so the
 * presentation layer can render a structured 422 response without re-querying
 * the tolerance settings.
 */
final class ToleranceExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $remainingBalance,
        public readonly string $maxAmount,
        public readonly string $percentage,
    ) {
        parent::__construct(
            "Remaining balance {$remainingBalance} exceeds tolerance threshold (max {$maxAmount}, percentage {$percentage}).",
        );
    }
}
