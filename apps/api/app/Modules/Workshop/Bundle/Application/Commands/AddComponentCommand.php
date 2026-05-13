<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;

/**
 * Command to append a new component line to a bundle.
 *
 * Exactly one of (product_id, service_id, nested_bundle_id) must be
 * non-null, matching the DB CHECK constraint. `component_id` is the
 * single logical identifier; the service dispatches it to the
 * appropriate FK column based on `component_type`.
 */
final readonly class AddComponentCommand
{
    public function __construct(
        public string $bundle_id,
        public BundleComponentType $component_type,
        public string $component_id,   // product_id | service_id | nested_bundle_id
        public string $quantity,       // scaled decimal string
        public string $unit_id,
        public ?string $override_unit_price,
        public bool $is_optional,
        public int $display_order,
        public ?string $notes,
        public string $tenant_id,
        public string $company_id,
    ) {}
}
