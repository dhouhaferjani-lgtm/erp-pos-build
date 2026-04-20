<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\ValueObjects;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;

/**
 * Snapshot line produced by `BundleExpansionService::expandForWorkOrder`.
 *
 * Pure projection — no Eloquent references, no DB state. Consumed by
 * Spec B (WorkOrder) to materialize bundles as document lines at
 * add-to-WO time. Spec B freezes this snapshot; later bundle edits
 * cannot retroactively alter historical WOs.
 *
 * `component_id` is nullable: the synthetic flat-price line emitted in
 * `fixed_bundle` pricing mode sets it to null because that line
 * represents the bundle header itself, not any single component.
 */
final readonly class BundleExpansionLine
{
    public function __construct(
        public BundleComponentType $component_type,
        public ?string $component_id,
        public string $display_name,
        public string $quantity,              // scaled decimal string
        public string $unit,                  // liter, hour, piece, ...
        public string $unit_price,            // scaled decimal string (CurrencyScale)
        public string $line_total,            // qty * unit_price; scaled decimal string
        public bool $is_optional,
        public bool $is_from_fixed_bundle,    // true for lines whose totals are informational
    ) {}
}
