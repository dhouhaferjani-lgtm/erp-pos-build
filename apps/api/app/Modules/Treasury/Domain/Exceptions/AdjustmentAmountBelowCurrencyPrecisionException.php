<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

/**
 * The requested adjustment amount normalizes to zero at the target
 * repository's currency scale (V3 gate C2) — EUR 0.005, or ANY sub-unit amount
 * on a scale-0 currency such as XOF/JPY.
 *
 * Refused BEFORE any insert: left unchecked it reaches the pgsql-only
 * `CHECK (amount > 0)` on `repository_adjustments` as SQLSTATE 23514 → HTTP
 * 500, rolling back the whole adjustment — a failure the sqlite suite
 * structurally cannot see (CLAUDE.md rule 20).
 */
final class AdjustmentAmountBelowCurrencyPrecisionException extends DomainException
{
    public function __construct(
        public readonly string $requestedAmount,
        public readonly string $currency,
        public readonly int $scale,
    ) {
        parent::__construct(
            "Adjustment amount {$requestedAmount} is below the smallest unit of {$currency} ".
            "(scale {$scale}); it normalizes to zero and is refused."
        );
    }
}
