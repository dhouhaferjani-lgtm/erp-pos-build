<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Modules\Workshop\WorkOrder\Application\Commands\CaptureApprovalCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderApproved;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCancelled;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderClosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderDiagnosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderInvoiced;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPartsNeeded;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPaused;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderQuoted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderResumed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderWaitingParts;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\StaleWorkOrderException;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderTransitionException;
use App\Modules\Workshop\WorkOrder\Domain\Services\StatusMachine;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PartNeed;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderStatusTransition;
use App\Modules\Workshop\WorkOrder\Infrastructure\Adapters\DocumentGenerationAdapter;
use App\Modules\Workshop\WorkOrder\Infrastructure\Adapters\InventoryReservationAdapter;
use App\PHPStan\Rules\WorkOrderStatusWriteOnlyViaTransitionService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The single write path for WorkOrder status changes. All other code paths
 * that need to change `WorkOrder::$status` must go through this service — the
 * custom PHPStan rule
 * {@see WorkOrderStatusWriteOnlyViaTransitionService}
 * enforces this at static-analysis time.
 *
 * Concurrency (Strategy A — application-level optimistic locking):
 *   The caller MAY supply `expected_updated_at` on the command. When present,
 *   the service re-reads the row under `SELECT ... FOR UPDATE` and compares
 *   against the stored `updated_at`. A mismatch throws
 *   {@see StaleWorkOrderException} which the HTTP boundary maps to 409
 *   `ConflictDetail { code: 'WORK_ORDER_STALE', current_updated_at, expected_updated_at }`.
 */
final readonly class WorkOrderTransitionService
{
    public function __construct(
        private WorkOrderRepositoryInterface $workOrders,
        private StatusMachine $statusMachine,
        private DocumentGenerationAdapter $documents,
        private InventoryReservationAdapter $reservations,
        private ConnectionInterface $db,
    ) {}

    public function transition(TransitionStatusCommand $command): WorkOrder
    {
        return $this->db->transaction(function () use ($command): WorkOrder {
            $wo = $this->loadForUpdate($command->work_order_id, $command->expected_updated_at);

            $from = $wo->status;
            $to = $command->to_status;

            if (! $this->statusMachine->isAllowed($from, $to)) {
                throw new WorkOrderTransitionException(
                    "Cannot transition WorkOrder {$wo->id} from {$from->value} to {$to->value}."
                );
            }

            $occurred = $command->occurred_at;

            // Pre-transition side effects that must succeed inside the same tx.
            $quoteDocumentId = null;
            $invoiceDocumentId = null;
            /** @var list<PartNeed> $partNeeds */
            $partNeeds = [];

            match ($to) {
                WorkOrderStatus::Quoted => $quoteDocumentId = $this->documents->generateQuote($wo),
                WorkOrderStatus::Approved => $partNeeds = $this->reservations->reserveFor($wo),
                WorkOrderStatus::Invoiced => $invoiceDocumentId = $this->documents->generateInvoice($wo),
                WorkOrderStatus::Cancelled => $this->reservations->releaseFor($wo, $command->reason_code ?? 'work_order_cancelled'),
                default => null,
            };

            if ($quoteDocumentId !== null) {
                $wo->quote_document_id = $quoteDocumentId;
            }
            if ($invoiceDocumentId !== null) {
                $wo->invoice_document_id = $invoiceDocumentId;
            }

            $wo->status = $to;
            $this->applyTimestamps($wo, $to, $occurred, $command);
            $this->workOrders->save($wo);

            $this->writeTransition($wo, $from, $to, $command->reason_code, $command->triggered_by_user_id, $command->context, $occurred);

            $this->dispatchTransitionEvent($wo, $to, $occurred, $command, $quoteDocumentId, $invoiceDocumentId);

            if ($to === WorkOrderStatus::Approved && $partNeeds !== []) {
                event(new WorkOrderPartsNeeded(
                    work_order_id: $wo->id,
                    needs: $partNeeds,
                    recorded_at: $occurred,
                ));
            }

            return $wo;
        });
    }

    public function captureApproval(CaptureApprovalCommand $command): WorkOrder
    {
        // Embed the approval evidence on the WO header BEFORE transitioning.
        return $this->db->transaction(function () use ($command): WorkOrder {
            $wo = $this->loadForUpdate($command->work_order_id, $command->expected_updated_at);
            $wo->approval_method = $command->approval_method;
            $wo->approval_captured_at = Carbon::instance($command->approval_captured_at);
            $wo->approval_captured_by_user_id = $command->approval_captured_by_user_id;
            $wo->approval_reference = $command->approval_reference;
            $this->workOrders->save($wo);

            return $this->transition(new TransitionStatusCommand(
                work_order_id: $command->work_order_id,
                to_status: WorkOrderStatus::Approved,
                reason_code: null,
                triggered_by_user_id: $command->approval_captured_by_user_id,
                occurred_at: $command->approval_captured_at,
                context: [
                    'approval_method' => $command->approval_method->value,
                    'approval_reference' => $command->approval_reference,
                ],
                // Already re-read + locked above — no need to re-check.
                expected_updated_at: null,
            ));
        });
    }

    private function loadForUpdate(string $workOrderId, ?\DateTimeImmutable $expectedUpdatedAt): WorkOrder
    {
        $wo = $this->workOrders->findForUpdate($workOrderId);
        if ($wo === null) {
            throw new RuntimeException("WorkOrder {$workOrderId} not found.");
        }

        if ($expectedUpdatedAt !== null && $wo->updated_at instanceof Carbon) {
            $current = $wo->updated_at->toDateTimeImmutable();
            if ($current->getTimestamp() !== $expectedUpdatedAt->getTimestamp()) {
                throw new StaleWorkOrderException(
                    workOrderId: $wo->id,
                    expectedUpdatedAt: $expectedUpdatedAt,
                    currentUpdatedAt: $current,
                );
            }
        }

        return $wo;
    }

    private function applyTimestamps(WorkOrder $wo, WorkOrderStatus $to, \DateTimeImmutable $occurredAt, TransitionStatusCommand $command): void
    {
        $ts = Carbon::instance($occurredAt);

        match ($to) {
            WorkOrderStatus::InProgress => $wo->started_at = $wo->started_at ?? $ts,
            WorkOrderStatus::Paused => $wo->paused_at = $ts,
            WorkOrderStatus::Completed => $wo->completed_at = $ts,
            WorkOrderStatus::Cancelled => $this->markCancelled($wo, $ts, $command->reason_code),
            default => null,
        };
    }

    private function markCancelled(WorkOrder $wo, Carbon $at, ?string $reasonCode): void
    {
        $wo->cancelled_at = $at;
        if ($reasonCode !== null) {
            $wo->cancellation_reason = CancellationReason::tryFrom($reasonCode);
        }
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function writeTransition(
        WorkOrder $wo,
        WorkOrderStatus $from,
        WorkOrderStatus $to,
        ?string $reasonCode,
        ?string $triggeredByUserId,
        ?array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
        WorkOrderStatusTransition::query()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason_code' => $reasonCode,
            'triggered_by_user_id' => $triggeredByUserId,
            'triggered_at' => Carbon::instance($occurredAt),
            'context' => $context,
        ]);
    }

    private function dispatchTransitionEvent(
        WorkOrder $wo,
        WorkOrderStatus $to,
        \DateTimeImmutable $occurredAt,
        TransitionStatusCommand $command,
        ?string $quoteDocumentId,
        ?string $invoiceDocumentId,
    ): void {
        $event = match ($to) {
            WorkOrderStatus::Diagnosed => new WorkOrderDiagnosed(
                work_order_id: $wo->id,
                diagnosis: $wo->diagnosis ?? '',
                diagnosed_at: $occurredAt,
            ),
            WorkOrderStatus::Quoted => new WorkOrderQuoted(
                work_order_id: $wo->id,
                quote_document_id: (string) ($quoteDocumentId ?? $wo->quote_document_id),
                estimated_grand_total: $wo->estimated_grand_total,
                currency: $wo->currency,
                quoted_at: $occurredAt,
            ),
            WorkOrderStatus::Approved => new WorkOrderApproved(
                work_order_id: $wo->id,
                approval_method: $wo->approval_method ?? ApprovalMethod::InPerson,
                estimated_grand_total: $wo->estimated_grand_total,
                currency: $wo->currency,
                approval_captured_at: $occurredAt,
            ),
            WorkOrderStatus::InProgress => new WorkOrderStarted(
                work_order_id: $wo->id,
                primary_technician_profile_id: $wo->primary_technician_profile_id ?? '',
                started_at: $occurredAt,
            ),
            WorkOrderStatus::Paused => new WorkOrderPaused(
                work_order_id: $wo->id,
                reason_code: $command->reason_code ?? 'paused',
                paused_at: $occurredAt,
            ),
            WorkOrderStatus::WaitingParts => new WorkOrderWaitingParts(
                work_order_id: $wo->id,
                needs: [],
                recorded_at: $occurredAt,
            ),
            WorkOrderStatus::Completed => new WorkOrderCompleted(
                work_order_id: $wo->id,
                completion_mileage: isset($command->context['completion_mileage'])
                    && is_int($command->context['completion_mileage'])
                    ? $command->context['completion_mileage']
                    : null,
                completed_at: $occurredAt,
            ),
            WorkOrderStatus::Invoiced => new WorkOrderInvoiced(
                work_order_id: $wo->id,
                invoice_document_id: (string) ($invoiceDocumentId ?? $wo->invoice_document_id),
                invoiced_at: $occurredAt,
            ),
            WorkOrderStatus::Closed => new WorkOrderClosed(
                work_order_id: $wo->id,
                closed_at: $occurredAt,
            ),
            WorkOrderStatus::Cancelled => new WorkOrderCancelled(
                work_order_id: $wo->id,
                reason_code: $command->reason_code ?? 'unspecified',
                cancelled_at: $occurredAt,
            ),
            WorkOrderStatus::Received => null,
        };

        // Resumed requires special handling: emitted only when transitioning
        // Paused → InProgress. We detect that the from_status was Paused.
        if ($to === WorkOrderStatus::InProgress && $wo->paused_at !== null) {
            event(new WorkOrderResumed(
                work_order_id: $wo->id,
                resumed_at: $occurredAt,
            ));
        }

        if ($event !== null) {
            event($event);
        }
    }
}
