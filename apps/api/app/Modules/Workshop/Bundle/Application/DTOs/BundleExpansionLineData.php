<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\DTOs;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\BundleExpansionLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire representation of a single expansion line emitted by
 * `BundleExpansionService::expandForWorkOrder`.
 *
 * `component_id` is nullable: the synthetic flat-price line emitted in
 * `fixed_bundle` pricing mode sets it to null because that line
 * represents the bundle header itself, not any single component.
 *
 * Example JSON for a fixed-bundle header line:
 *
 * ```json
 * {
 *   "component_type": "nested_bundle",
 *   "component_id": null,
 *   "display_name": "Vidange 10k Diesel",
 *   "quantity": "1.000",
 *   "unit": "bundle",
 *   "unit_price": "120.000",
 *   "line_total": "120.000",
 *   "is_optional": false,
 *   "is_from_fixed_bundle": false
 * }
 * ```
 */
#[TypeScript]
final class BundleExpansionLineData extends Data
{
    public function __construct(
        public BundleComponentType $component_type,
        public ?string $component_id,
        public string $display_name,
        public string $quantity,
        public string $unit,
        public string $unit_price,
        public string $line_total,
        public bool $is_optional,
        public bool $is_from_fixed_bundle,
    ) {}

    public static function fromValueObject(BundleExpansionLine $line): self
    {
        return new self(
            component_type: $line->component_type,
            component_id: $line->component_id,
            display_name: $line->display_name,
            quantity: $line->quantity,
            unit: $line->unit,
            unit_price: $line->unit_price,
            line_total: $line->line_total,
            is_optional: $line->is_optional,
            is_from_fixed_bundle: $line->is_from_fixed_bundle,
        );
    }
}
