<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Application\Commands;

/**
 * Replace the complete vehicle-applicability set for a bundle.
 *
 * Each applicability is either "universal" (all platform_vehicle_id +
 * vehicle_type null) or vehicle-scoped (both non-null).
 *
 * @phpstan-type ApplicabilityInput array{
 *     platform_vehicle_id: ?string,
 *     vehicle_type: ?string,
 *     vehicle_display: ?string,
 *     year_from: ?int,
 *     year_to: ?int,
 * }
 */
final readonly class SetVehicleApplicabilitiesCommand
{
    /**
     * @param  list<ApplicabilityInput>  $applicabilities
     */
    public function __construct(
        public string $bundle_id,
        public array $applicabilities,
    ) {}
}
