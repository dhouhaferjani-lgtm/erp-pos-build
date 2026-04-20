<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\WorkOrderTotals;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for WorkOrder totals. Money fields are scale-preserving
 * numeric-strings produced by `TotalsCalculator` (bcmath, no float).
 */
#[TypeScript]
final class WorkOrderTotalsData extends Data
{
    public function __construct(
        public string $parts_total,
        public string $labor_total,
        public string $other_total,
        public string $tax_total,
        public string $grand_total,
    ) {}

    public static function fromVo(WorkOrderTotals $vo): self
    {
        return new self(
            parts_total: $vo->parts_total,
            labor_total: $vo->labor_total,
            other_total: $vo->other_total,
            tax_total: $vo->tax_total,
            grand_total: $vo->grand_total,
        );
    }

    public static function zero(string $zero): self
    {
        return new self(
            parts_total: $zero,
            labor_total: $zero,
            other_total: $zero,
            tax_total: $zero,
            grand_total: $zero,
        );
    }
}
