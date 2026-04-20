<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

/**
 * Add a ServiceBundle to the WorkOrder; it is expanded via
 * BundleExpansionService::expandForWorkOrder and materialized as lines.
 *
 * `quantity` is a numeric-string; typically "1" for a single bundle.
 * `vehicle_id` optional — reserved for vehicle-aware expansion (Plan A.7).
 */
final readonly class AddBundleCommand
{
    public function __construct(
        public string $work_order_id,
        public string $bundle_id,
        public string $quantity,
        public ?string $vehicle_id,
    ) {}
}
