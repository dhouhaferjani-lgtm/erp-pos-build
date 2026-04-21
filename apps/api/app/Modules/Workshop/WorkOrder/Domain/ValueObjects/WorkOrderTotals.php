<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\ValueObjects;

/**
 * Aggregated money roll-ups for a WorkOrder — either estimated (from the quote
 * phase) or actual (after completion).
 *
 * All money fields are scale-preserving numeric-strings in the WorkOrder's
 * currency (TND → 3 decimals, EUR → 2 decimals). `TotalsCalculator` produces
 * instances of this VO via `CurrencyScale::bcformat` — never raw float.
 */
final readonly class WorkOrderTotals
{
    public function __construct(
        public string $parts_total,
        public string $labor_total,
        public string $other_total,
        public string $tax_total,
        public string $grand_total,
    ) {}
}
