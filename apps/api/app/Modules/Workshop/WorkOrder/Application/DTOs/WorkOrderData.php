<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for a fully-hydrated WorkOrder (detail page payload).
 *
 * Financial fields (`estimated_totals`, `actual_totals`) are emitted
 * as `WorkOrderTotalsData`. The list endpoint and permission-redacted
 * flow use `WorkOrderListItemData` instead for page-load efficiency.
 */
#[TypeScript]
final class WorkOrderData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public ?string $location_id,
        public string $work_order_number,
        public WorkOrderStatus $status,
        public WorkOrderType $type,
        public string $customer_partner_id,
        public string $customer_display_name,
        public string $vehicle_id,
        public string $vehicle_display_name,
        public string $opened_by_user_id,
        public ?string $primary_technician_profile_id,
        public ?string $primary_technician_display_name,
        public ?string $appointment_id,
        public ?int $mileage_at_intake,
        public ?string $customer_complaint,
        public ?string $diagnosis,
        public ?string $internal_notes,
        public ?string $scheduled_start_at,
        public ?string $scheduled_end_at,
        public ?string $promised_at,
        public ?string $started_at,
        public ?string $paused_at,
        public ?string $completed_at,
        public ?string $cancelled_at,
        public ?CancellationReason $cancellation_reason,
        public ?string $approval_captured_at,
        public ?ApprovalMethod $approval_method,
        public ?string $approval_reference,
        public string $currency,
        public WorkOrderTotalsData $estimated_totals,
        public WorkOrderTotalsData $actual_totals,
        public ?string $quote_document_id,
        public ?string $invoice_document_id,
        /** @var DataCollection<int, WorkOrderLineData> */
        #[DataCollectionOf(WorkOrderLineData::class)]
        public DataCollection $lines,
        /** @var DataCollection<int, WorkOrderAssignmentData> */
        #[DataCollectionOf(WorkOrderAssignmentData::class)]
        public DataCollection $assignments,
        /** @var DataCollection<int, WorkOrderStatusTransitionData> */
        #[DataCollectionOf(WorkOrderStatusTransitionData::class)]
        public DataCollection $status_history,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(WorkOrder $wo): self
    {
        $estimated = new WorkOrderTotalsData(
            parts_total: $wo->estimated_parts_total,
            labor_total: $wo->estimated_labor_total,
            other_total: $wo->estimated_other_total,
            tax_total: $wo->estimated_tax_total,
            grand_total: $wo->estimated_grand_total,
        );
        $actual = new WorkOrderTotalsData(
            parts_total: $wo->actual_parts_total,
            labor_total: $wo->actual_labor_total,
            other_total: $wo->actual_other_total,
            tax_total: $wo->actual_tax_total,
            grand_total: $wo->actual_grand_total,
        );

        /** @var DataCollection<int, WorkOrderLineData> $lines */
        $lines = WorkOrderLineData::collect(
            $wo->lines->map(static fn ($line): WorkOrderLineData => WorkOrderLineData::fromModel($line)),
            DataCollection::class,
        );
        /** @var DataCollection<int, WorkOrderAssignmentData> $assignments */
        $assignments = WorkOrderAssignmentData::collect(
            $wo->assignments->map(static fn ($a): WorkOrderAssignmentData => WorkOrderAssignmentData::fromModel($a)),
            DataCollection::class,
        );
        /** @var DataCollection<int, WorkOrderStatusTransitionData> $transitions */
        $transitions = WorkOrderStatusTransitionData::collect(
            $wo->statusTransitions->map(
                static fn ($t): WorkOrderStatusTransitionData => WorkOrderStatusTransitionData::fromModel($t)
            ),
            DataCollection::class,
        );

        return new self(
            id: $wo->id,
            tenant_id: $wo->tenant_id,
            company_id: $wo->company_id,
            location_id: $wo->location_id,
            work_order_number: $wo->work_order_number,
            status: $wo->status,
            type: $wo->type,
            customer_partner_id: $wo->customer_partner_id,
            customer_display_name: (string) ($wo->customer->name ?? ''),
            vehicle_id: $wo->vehicle_id,
            vehicle_display_name: WorkOrderListItemData::fromModel($wo)->vehicle_display_name,
            opened_by_user_id: $wo->opened_by_user_id,
            primary_technician_profile_id: $wo->primary_technician_profile_id,
            primary_technician_display_name: $wo->primaryTechnician?->user->name,
            appointment_id: $wo->appointment_id,
            mileage_at_intake: $wo->mileage_at_intake,
            customer_complaint: $wo->customer_complaint,
            diagnosis: $wo->diagnosis,
            internal_notes: $wo->internal_notes,
            scheduled_start_at: $wo->scheduled_start_at?->toIso8601String(),
            scheduled_end_at: $wo->scheduled_end_at?->toIso8601String(),
            promised_at: $wo->promised_at?->toIso8601String(),
            started_at: $wo->started_at?->toIso8601String(),
            paused_at: $wo->paused_at?->toIso8601String(),
            completed_at: $wo->completed_at?->toIso8601String(),
            cancelled_at: $wo->cancelled_at?->toIso8601String(),
            cancellation_reason: $wo->cancellation_reason,
            approval_captured_at: $wo->approval_captured_at?->toIso8601String(),
            approval_method: $wo->approval_method,
            approval_reference: $wo->approval_reference,
            currency: $wo->currency,
            estimated_totals: $estimated,
            actual_totals: $actual,
            quote_document_id: $wo->quote_document_id,
            invoice_document_id: $wo->invoice_document_id,
            lines: $lines,
            assignments: $assignments,
            status_history: $transitions,
            created_at: ($wo->created_at ?? now())->toIso8601String(),
            updated_at: $wo->updated_at?->toIso8601String(),
        );
    }
}
