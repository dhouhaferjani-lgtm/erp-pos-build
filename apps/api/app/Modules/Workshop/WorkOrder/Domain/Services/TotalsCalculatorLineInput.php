<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Services;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;

/**
 * Lightweight input VO accepted by `TotalsCalculator::compute()`.
 *
 * Decouples the totals math from the `WorkOrderLine` Eloquent model so the
 * calculator stays pure domain — callable from unit tests without database
 * setup, and reusable by read-model projections.
 */
final readonly class TotalsCalculatorLineInput
{
    public function __construct(
        public WorkOrderLineType $line_type,
        public string $line_total_excl_tax,
        public string $line_total_tax,
        public string $line_total_incl_tax,
        public bool $is_bundle_informational = false,
    ) {}
}
