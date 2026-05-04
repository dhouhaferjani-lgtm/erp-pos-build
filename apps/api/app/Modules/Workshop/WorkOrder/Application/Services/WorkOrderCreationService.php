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
use RuntimeException;

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
        string $tenantId,
        string $companyId,
    ): WorkOrder {
        return $this->db->transaction(function () use ($appointmentId, $plannedServices, $vehicleId, $partnerId, $tenantId, $companyId): WorkOrder {
            // Defense-in-depth: api.workshop.001/002 — the partner_id supplied
            // by the appointment is verified to belong to the same tenant +
            // company as the appointment itself. A foreign partner_id (planted
            // by a malicious appointment row or a future cross-tenant write
            // path) is rejected here rather than leaking into the work order.
            $this->assertPartnerInScope($partnerId, $tenantId, $companyId);

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
                tenant_id: $tenantId,
                company_id: $companyId,
                location_id: null,
                type: WorkOrderType::Maintenance,
                customer_partner_id: $partnerId,
                vehicle_id: $vehicleId,
                opened_by_user_id: $this->resolveOpenedByUserId($tenantId),
                primary_technician_profile_id: null,
                appointment_id: $appointmentId,
                mileage_at_intake: null,
                customer_complaint: null,
                internal_notes: null,
                scheduled_start_at: null,
                scheduled_end_at: null,
                promised_at: null,
                currency: $this->resolveCurrency($tenantId, $companyId),
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

    /**
     * api.workshop.001/002 — replace bare Partner::find with a
     * tenant+company-scoped read. A cross-tenant partner_id surfaces as
     * RuntimeException rather than as silent admission to the work order.
     *
     * Scoping the lookup with both tenant_id and company_id satisfies the
     * Treasury R3 Finding-14 invariant and matches the api.cart precedent
     * for partner-id reads anchored on caller-supplied input.
     */
    private function assertPartnerInScope(string $partnerId, string $tenantId, string $companyId): void
    {
        $exists = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($partnerId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException(
                "Partner {$partnerId} not found in tenant={$tenantId} company={$companyId} — refusing cross-tenant work order creation."
            );
        }
    }

    /**
     * api.workshop.003 — pick a tenant-scoped user to own the appointment-
     * sourced work order. Was: bare User::query()->where('tenant_id'=>...).
     * The bare lookup is structurally tenant-scoped already, but the
     * upstream Partner::find at the original line 144 was unscoped. Now
     * the partner has been validated above; the user lookup remains
     * tenant-only by design (no company_id on users).
     */
    private function resolveOpenedByUserId(string $tenantId): string
    {
        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->first();

        if ($user === null) {
            throw new RuntimeException('No user available to own the appointment-sourced WorkOrder.');
        }

        return (string) $user->id;
    }

    /**
     * api.workshop.004 — replace bare Partner::find + Company::find chain
     * with a tenant+company-scoped Company::query. Currency lookup is
     * structurally protected: the company is loaded by its own id within
     * the active tenant scope, so a foreign company UUID would simply miss
     * and the safe TND fallback applies.
     */
    private function resolveCurrency(string $tenantId, string $companyId): string
    {
        /** @var Company|null $company */
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($companyId)
            ->first();

        if ($company === null) {
            return 'TND';
        }

        return $company->currency;
    }
}
