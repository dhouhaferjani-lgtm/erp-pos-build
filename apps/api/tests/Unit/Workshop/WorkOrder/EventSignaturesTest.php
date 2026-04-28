<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderApproved;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCancelled;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderClosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCreated;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderDiagnosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderInvoiced;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderLineUpdated;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPartsNeeded;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPaused;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderQuoted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderResumed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderWaitingParts;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PartNeed;
use PHPUnit\Framework\TestCase;

/**
 * Validates the shape of the 14 domain events for the WorkOrder aggregate.
 *
 * The 6 "canonical lifecycle" events (WorkOrderStarted, Paused, Resumed,
 * Completed, Cancelled, Closed) are shared contracts with other plans
 * (Plan C subscribes to time-entry lifecycle, Plan D subscribes to Closed).
 * Their constructor signatures are locked and MUST NOT drift.
 */
final class EventSignaturesTest extends TestCase
{
    public function test_work_order_created(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T09:00:00+00:00');
        $e = new WorkOrderCreated(
            work_order_id: 'wo-1',
            vehicle_id: 'veh-1',
            customer_partner_id: 'cust-1',
            type: WorkOrderType::Repair,
            created_at: $at,
        );
        $this->assertSame('wo-1', $e->work_order_id);
        $this->assertSame('veh-1', $e->vehicle_id);
        $this->assertSame('cust-1', $e->customer_partner_id);
        $this->assertSame(WorkOrderType::Repair, $e->type);
        $this->assertSame($at, $e->created_at);
    }

    public function test_work_order_diagnosed(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T09:30:00+00:00');
        $e = new WorkOrderDiagnosed(
            work_order_id: 'wo-1',
            diagnosis: 'Front brake pads worn under 1mm.',
            diagnosed_at: $at,
        );
        $this->assertSame('wo-1', $e->work_order_id);
        $this->assertStringContainsString('brake', $e->diagnosis);
        $this->assertSame($at, $e->diagnosed_at);
    }

    public function test_work_order_quoted(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T10:00:00+00:00');
        $e = new WorkOrderQuoted(
            work_order_id: 'wo-1',
            quote_document_id: 'doc-1',
            estimated_grand_total: '450.000',
            currency: 'TND',
            quoted_at: $at,
        );
        $this->assertSame('doc-1', $e->quote_document_id);
        $this->assertSame('450.000', $e->estimated_grand_total);
        $this->assertSame('TND', $e->currency);
    }

    public function test_work_order_approved(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T10:30:00+00:00');
        $e = new WorkOrderApproved(
            work_order_id: 'wo-1',
            approval_method: ApprovalMethod::Phone,
            estimated_grand_total: '450.000',
            currency: 'TND',
            approval_captured_at: $at,
        );
        $this->assertSame(ApprovalMethod::Phone, $e->approval_method);
        $this->assertSame('450.000', $e->estimated_grand_total);
    }

    public function test_work_order_started_canonical_signature(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T11:00:00+00:00');
        $e = new WorkOrderStarted(
            work_order_id: 'wo-1',
            primary_technician_profile_id: 'tp-1',
            started_at: $at,
        );
        // Lock these property names — Plan C listener subscribes.
        $this->assertSame('wo-1', $e->work_order_id);
        $this->assertSame('tp-1', $e->primary_technician_profile_id);
        $this->assertSame($at, $e->started_at);
    }

    public function test_work_order_paused_canonical_signature(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T11:30:00+00:00');
        $e = new WorkOrderPaused(
            work_order_id: 'wo-1',
            reason_code: 'customer_callback',
            paused_at: $at,
        );
        $this->assertSame('customer_callback', $e->reason_code);
        $this->assertSame($at, $e->paused_at);
    }

    public function test_work_order_resumed_canonical_signature(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T12:00:00+00:00');
        $e = new WorkOrderResumed(
            work_order_id: 'wo-1',
            resumed_at: $at,
        );
        $this->assertSame('wo-1', $e->work_order_id);
        $this->assertSame($at, $e->resumed_at);
    }

    public function test_work_order_waiting_parts(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T12:30:00+00:00');
        $needs = [
            new PartNeed(
                product_id: 'prod-1',
                display_name: 'Brake pad set, front',
                quantity: '1',
                unit: 'set',
                vehicle_id: 'veh-1',
                vehicle_display: 'Toyota Corolla 2019',
                urgency: 'high',
                notes: null,
            ),
        ];
        $e = new WorkOrderWaitingParts(
            work_order_id: 'wo-1',
            needs: $needs,
            recorded_at: $at,
        );
        $this->assertCount(1, $e->needs);
        $this->assertSame('prod-1', $e->needs[0]->product_id);
    }

    public function test_work_order_parts_needed(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T12:45:00+00:00');
        $need = new PartNeed(
            product_id: 'prod-2',
            display_name: 'Air filter',
            quantity: '2',
            unit: 'pc',
            vehicle_id: 'veh-1',
            vehicle_display: 'Toyota Corolla 2019',
            urgency: 'medium',
            notes: 'OEM preferred',
        );
        $e = new WorkOrderPartsNeeded(
            work_order_id: 'wo-1',
            needs: [$need],
            recorded_at: $at,
        );
        $this->assertCount(1, $e->needs);
        $this->assertSame('OEM preferred', $e->needs[0]->notes);
    }

    public function test_work_order_line_updated(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T13:00:00+00:00');
        $e = new WorkOrderLineUpdated(
            work_order_id: 'wo-1',
            line_id: 'line-1',
            change_type: 'added',
            updated_at: $at,
        );
        $this->assertSame('line-1', $e->line_id);
        $this->assertSame('added', $e->change_type);
    }

    public function test_work_order_completed_canonical_signature(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T14:00:00+00:00');
        $e = new WorkOrderCompleted(
            work_order_id: 'wo-1',
            completion_mileage: 123456,
            completed_at: $at,
        );
        $this->assertSame(123456, $e->completion_mileage);
        $this->assertSame($at, $e->completed_at);
    }

    public function test_work_order_completed_allows_null_mileage(): void
    {
        $e = new WorkOrderCompleted(
            work_order_id: 'wo-1',
            completion_mileage: null,
            completed_at: new \DateTimeImmutable,
        );
        $this->assertNull($e->completion_mileage);
    }

    public function test_work_order_invoiced(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T15:00:00+00:00');
        $e = new WorkOrderInvoiced(
            work_order_id: 'wo-1',
            invoice_document_id: 'doc-inv-1',
            invoiced_at: $at,
        );
        $this->assertSame('doc-inv-1', $e->invoice_document_id);
    }

    public function test_work_order_closed_canonical_signature(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T16:00:00+00:00');
        $e = new WorkOrderClosed(
            work_order_id: 'wo-1',
            closed_at: $at,
        );
        // Plan D listener subscribes — lock property names.
        $this->assertSame('wo-1', $e->work_order_id);
        $this->assertSame($at, $e->closed_at);
    }

    public function test_work_order_cancelled_canonical_signature(): void
    {
        $at = new \DateTimeImmutable('2026-04-20T17:00:00+00:00');
        $e = new WorkOrderCancelled(
            work_order_id: 'wo-1',
            reason_code: CancellationReason::CustomerDeclined->value,
            cancelled_at: $at,
        );
        $this->assertSame('customer_declined', $e->reason_code);
    }

    public function test_all_events_are_final_readonly(): void
    {
        $classes = [
            WorkOrderCreated::class,
            WorkOrderDiagnosed::class,
            WorkOrderQuoted::class,
            WorkOrderApproved::class,
            WorkOrderStarted::class,
            WorkOrderPaused::class,
            WorkOrderResumed::class,
            WorkOrderWaitingParts::class,
            WorkOrderPartsNeeded::class,
            WorkOrderLineUpdated::class,
            WorkOrderCompleted::class,
            WorkOrderInvoiced::class,
            WorkOrderClosed::class,
            WorkOrderCancelled::class,
        ];
        $this->assertCount(14, $classes);
        foreach ($classes as $fqcn) {
            $rc = new \ReflectionClass($fqcn);
            $this->assertTrue($rc->isFinal(), "{$fqcn} must be final");
            $this->assertTrue($rc->isReadOnly(), "{$fqcn} must be readonly");
        }
    }
}
