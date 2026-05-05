<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Events\AppointmentConvertedToWorkOrder;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentNotConvertibleException;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderCreationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PlannedServiceRef;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Orchestrates Appointment → WorkOrder conversion.
 *
 * Per Spec D §7.2:
 *  1. `findForUpdate($id)` acquires a row lock inside the enclosing tx.
 *  2. Guard: appointment status MUST be in {Confirmed, CheckedIn}.
 *  3. Guard: appointment MUST NOT already be linked to a work_order_id.
 *  4. Map `scheduling_appointment_services` rows to
 *     `list<PlannedServiceRef>` using {@see PlannedServiceRef::TYPE_*}.
 *  5. Call the Plan-B-owned {@see WorkOrderCreationServiceInterface}.
 *  6. Set `appointment.work_order_id = $wo->id`.
 *  7. Dispatch {@see AppointmentConvertedToWorkOrder} event.
 *
 * The appointment's status is NOT advanced here — Plan B's WorkOrder
 * lifecycle events (WorkOrderStarted / Completed / Cancelled / Closed)
 * are mirrored onto the appointment by the 4 MirrorAppointmentOn*
 * listeners (Task 12). This service leaves status at the pre-conversion
 * value (Confirmed or CheckedIn) until Plan B drives a mirror.
 */
final class AppointmentConversionService
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly WorkOrderCreationServiceInterface $workOrderCreation,
    ) {}

    /**
     * @throws AppointmentNotConvertibleException
     */
    public function convertToWorkOrder(string $appointmentId, ?string $convertedByUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($appointmentId, $convertedByUserId): WorkOrder {
            $appointment = $this->appointments->findForUpdate($appointmentId);

            $this->assertConvertible($appointment);

            /** @var list<PlannedServiceRef> $plannedServices */
            $plannedServices = $appointment->services
                ->map(fn ($service): PlannedServiceRef => new PlannedServiceRef(
                    service_ref_type: $service->service_ref_type,
                    service_ref_id: $service->service_ref_id,
                ))
                ->values()
                ->all();

            $vehicleId = $appointment->vehicle_id;
            $partnerId = $appointment->customer_partner_id;
            if ($vehicleId === null || $partnerId === null) {
                throw new AppointmentNotConvertibleException(
                    "Appointment {$appointment->id} is missing vehicle or customer partner — both are required for conversion."
                );
            }

            $workOrder = $this->workOrderCreation->createFromAppointment(
                appointmentId: $appointment->id,
                plannedServices: $plannedServices,
                vehicleId: $vehicleId,
                partnerId: $partnerId,
                tenantId: $appointment->tenant_id,
                companyId: $appointment->company_id,
            );

            $appointment->work_order_id = $workOrder->id;
            $this->appointments->save($appointment);

            Event::dispatch(new AppointmentConvertedToWorkOrder(
                appointment_id: $appointment->id,
                work_order_id: $workOrder->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                converted_by_user_id: $convertedByUserId,
                occurred_at: new \DateTimeImmutable,
            ));

            return $workOrder;
        });
    }

    /**
     * @throws AppointmentNotConvertibleException
     */
    private function assertConvertible(Appointment $appointment): void
    {
        if ($appointment->work_order_id !== null) {
            throw AppointmentNotConvertibleException::alreadyConverted(
                $appointment->id,
                $appointment->work_order_id,
            );
        }
        if (! in_array($appointment->status, [AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true)) {
            throw AppointmentNotConvertibleException::forStatus($appointment->id, $appointment->status);
        }
    }
}
