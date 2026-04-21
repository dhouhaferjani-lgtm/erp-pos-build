<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;

/**
 * Partial update of an existing bundle component line.
 *
 * Every field is nullable — a null value means "do not touch this field".
 * When both `component_type` and `component_id` are provided together,
 * the authoring service rewires the FK columns (and re-runs cycle
 * detection for `NestedBundle`). Supplying only one of the pair raises
 * a validation error at the HTTP layer.
 */
final readonly class UpdateComponentCommand
{
    public function __construct(
        public string $bundle_id,
        public string $component_id,
        public ?BundleComponentType $component_type,
        public ?string $new_component_reference_id,
        public ?string $quantity,
        public ?string $unit_id,
        public ?string $override_unit_price,
        public bool $override_unit_price_provided,
        public ?bool $is_optional,
        public ?int $display_order,
        public ?string $notes,
        public bool $notes_provided,
    ) {}
}
