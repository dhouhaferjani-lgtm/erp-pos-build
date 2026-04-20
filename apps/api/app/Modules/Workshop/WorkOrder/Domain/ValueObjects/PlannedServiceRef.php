<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Tagged-union VO referenced by Spec D's Appointment → WorkOrder conversion
 * (`WorkOrderCreationServiceInterface::createFromAppointment`).
 *
 * Services and ServiceBundles are two distinct aggregates; encoding the
 * distinction in a typed VO avoids `mixed` / ambiguous-id confusion and
 * makes the PHPDoc list-type precise for PHPStan level 8.
 */
final class PlannedServiceRef
{
    public const TYPE_SERVICE = 'service';

    public const TYPE_BUNDLE = 'bundle';

    public function __construct(
        public readonly string $service_ref_type,
        public readonly string $service_ref_id,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (! in_array($this->service_ref_type, [self::TYPE_SERVICE, self::TYPE_BUNDLE], true)) {
            throw new InvalidArgumentException(
                "PlannedServiceRef::service_ref_type must be 'service' or 'bundle', got '{$this->service_ref_type}'."
            );
        }
    }
}
