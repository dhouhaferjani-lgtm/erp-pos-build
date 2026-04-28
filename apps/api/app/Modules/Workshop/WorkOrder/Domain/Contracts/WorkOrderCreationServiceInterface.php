<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Contracts;

use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PlannedServiceRef;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;

/**
 * Creation-from-Appointment service contract consumed by Spec D's Scheduling
 * module (Appointment → WorkOrder conversion).
 *
 * **Ownership note:** the canonical location of this interface is
 * `App\Modules\Scheduling\Domain\Contracts\WorkOrderCreationServiceInterface`
 * (consumer-owned, per Dependency-Inversion Principle). Spec D has not yet
 * landed, so we declare this placeholder inside Workshop for now. When Spec D
 * merges, this file is deleted and `WorkOrderCreationService` (Task 14) will
 * implement the Scheduling-side interface — the method signature is locked
 * here so the swap is drop-in.
 *
 * `$plannedServices` is a list of tagged-union refs (service vs bundle) so
 * we don't encode polymorphism via `mixed`/ambiguous IDs. See
 * `PlannedServiceRef` (Task 10.5) for the VO shape.
 */
interface WorkOrderCreationServiceInterface
{
    /**
     * @param  list<PlannedServiceRef>  $plannedServices
     */
    public function createFromAppointment(
        string $appointmentId,
        array $plannedServices,
        string $vehicleId,
        string $partnerId,
    ): WorkOrder;
}
