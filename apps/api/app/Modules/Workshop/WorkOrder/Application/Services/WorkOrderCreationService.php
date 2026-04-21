<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Workshop\WorkOrder\Application\Commands\AddBundleCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\AddLineCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\CreateWorkOrderCommand;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderCreationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PlannedServiceRef;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Database\ConnectionInterface;

/**
 * Orchestrates Appointment → WorkOrder conversion (Spec D consumer).
 *
 * Creates a new WorkOrder in Received status with scheduled_start_at /
 * scheduled_end_at populated from the appointment, then materializes each
 * PlannedServiceRef into either a Labor line (service) or an expanded
 * bundle (via WorkOrderBundleService).
 *
 * Implements the Scheduling-owned contract placeholder at
 * {@see WorkOrderCreationServiceInterface}. The placeholder will be
 * replaced by Spec D's canonical interface once Scheduling lands; the
 * method signature is locked so the swap is drop-in.
 */
final readonly class WorkOrderCreationService implements WorkOrderCreationServiceInterface
{
    public function __construct(
        private WorkOrderAuthoringService $authoring,
        private WorkOrderLineService $lineService,
        private WorkOrderBundleService $bundleService,
        private ConnectionInterface $db,
    ) {}

    /**
     * @param  list<PlannedServiceRef>  $plannedServices
     */
    public function createFromAppointment(
        string $appointmentId,
        array $plannedServices,
        string $vehicleId,
        string $partnerId,
    ): WorkOrder {
        return $this->db->transaction(function () use ($appointmentId, $plannedServices, $vehicleId, $partnerId): WorkOrder {
            // Appointment carries the when + who + specialty, but the public
            // contract only exposes ids (vehicle + partner). Scheduling
            // auto-enriches scheduled_start/end + primary_technician via its
            // own API layer when the full appointment DTO is provided; here
            // we create a skeleton WO that the scheduling listener then
            // patches before any status transition.
            //
            // `appointment_id` is set here so the WO↔Appointment link is
            // bidirectional from the first save (closes audit finding 🟠-1).
            // The reverse direction (appointment.work_order_id = $wo->id)
            // is written by AppointmentConversionService after this returns.
            $wo = $this->authoring->create(new CreateWorkOrderCommand(
                tenant_id: $this->resolveTenantId($partnerId),
                company_id: $this->resolveCompanyId($partnerId),
                location_id: null,
                type: WorkOrderType::Maintenance,
                customer_partner_id: $partnerId,
                vehicle_id: $vehicleId,
                opened_by_user_id: $this->resolveOpenedByUserId($partnerId),
                primary_technician_profile_id: null,
                appointment_id: $appointmentId,
                mileage_at_intake: null,
                customer_complaint: null,
                internal_notes: null,
                scheduled_start_at: null,
                scheduled_end_at: null,
                promised_at: null,
                currency: $this->resolveCurrency($partnerId),
            ));

            foreach ($plannedServices as $planned) {
                if ($planned->service_ref_type === PlannedServiceRef::TYPE_BUNDLE) {
                    $this->bundleService->addBundle(new AddBundleCommand(
                        work_order_id: $wo->id,
                        bundle_id: $planned->service_ref_id,
                        quantity: '1',
                        vehicle_id: $vehicleId,
                    ));

                    continue;
                }

                // TYPE_SERVICE — add as a Labor line. Without richer
                // service-catalog metadata at this layer, use placeholder
                // display + unit hour + zero price; pricing refinement
                // happens in subsequent updateLine calls from the
                // scheduling/planning UI.
                $this->lineService->addLine(new AddLineCommand(
                    work_order_id: $wo->id,
                    line_type: WorkOrderLineType::Labor,
                    product_id: null,
                    service_id: $planned->service_ref_id,
                    display_name: 'Planned service',
                    sku_or_code: null,
                    description: null,
                    quantity: '1',
                    unit: 'hour',
                    unit_price: '0',
                    tax_rate: '0',
                    discount_percent: '0',
                    labor_hours_estimated: '1',
                    assigned_technician_profile_id: null,
                    is_customer_supplied: false,
                ));
            }

            return $wo->fresh() ?? $wo;
        });
    }

    private function resolveTenantId(string $partnerId): string
    {
        $partner = Partner::query()->find($partnerId);
        if ($partner === null) {
            throw new \RuntimeException("Partner {$partnerId} not found — cannot resolve tenant.");
        }

        return $partner->tenant_id;
    }

    private function resolveCompanyId(string $partnerId): string
    {
        $partner = Partner::query()->find($partnerId);
        if ($partner === null) {
            throw new \RuntimeException("Partner {$partnerId} not found — cannot resolve company.");
        }

        return $partner->company_id;
    }

    private function resolveOpenedByUserId(string $partnerId): string
    {
        $partner = Partner::query()->find($partnerId);
        if ($partner === null) {
            throw new \RuntimeException("Partner {$partnerId} not found.");
        }

        $user = User::query()
            ->where('tenant_id', $partner->tenant_id)
            ->first();

        if ($user === null) {
            throw new \RuntimeException('No user available to own the appointment-sourced WorkOrder.');
        }

        return (string) $user->id;
    }

    private function resolveCurrency(string $partnerId): string
    {
        $partner = Partner::query()->find($partnerId);
        if ($partner === null) {
            return 'TND';
        }

        /** @var Company|null $company */
        $company = Company::query()->find($partner->company_id);
        if ($company === null) {
            return 'TND';
        }

        return $company->currency;
    }
}
