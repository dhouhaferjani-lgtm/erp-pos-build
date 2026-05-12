<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Workshop\WorkOrder\Application\Commands\CaptureApprovalCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderDiagnosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\StaleWorkOrderException;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderTransitionException;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderStatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class TransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_transition_moves_status_and_writes_audit_row(): void
    {
        Event::fake([WorkOrderDiagnosed::class]);

        $wo = WorkOrder::factory()->create();

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Diagnosed,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            tenant_id: $wo->tenant_id,
            company_id: $wo->company_id,
            context: null,
        ));

        $this->assertSame(WorkOrderStatus::Diagnosed, $updated->status);
        $this->assertSame(
            1,
            WorkOrderStatusTransition::query()->where('work_order_id', $wo->id)->count(),
        );
        Event::assertDispatched(WorkOrderDiagnosed::class);
    }

    public function test_illegal_transition_raises_exception(): void
    {
        $wo = WorkOrder::factory()->create(); // Received

        $service = $this->app->make(WorkOrderTransitionService::class);

        $this->expectException(WorkOrderTransitionException::class);
        $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Invoiced,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            tenant_id: $wo->tenant_id,
            company_id: $wo->company_id,
            context: null,
        ));
    }

    public function test_stale_updated_at_raises_stale_exception(): void
    {
        $wo = WorkOrder::factory()->create();
        $stale = (new \DateTimeImmutable)->modify('-1 year');

        $service = $this->app->make(WorkOrderTransitionService::class);

        $this->expectException(StaleWorkOrderException::class);
        $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Diagnosed,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            tenant_id: $wo->tenant_id,
            company_id: $wo->company_id,
            context: null,
            expected_updated_at: $stale,
        ));
    }

    public function test_capture_approval_transitions_to_approved_and_records_evidence(): void
    {
        $wo = WorkOrder::factory()->quoted()->create();

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->captureApproval(new CaptureApprovalCommand(
            work_order_id: $wo->id,
            approval_method: ApprovalMethod::Phone,
            approval_captured_by_user_id: $wo->opened_by_user_id,
            approval_reference: 'Ref-123',
            approval_captured_at: new \DateTimeImmutable,
            tenant_id: $wo->tenant_id,
            company_id: $wo->company_id,
        ));

        $this->assertSame(WorkOrderStatus::Approved, $updated->status);
        $this->assertSame(ApprovalMethod::Phone, $updated->approval_method);
        $this->assertSame('Ref-123', $updated->approval_reference);
    }

    public function test_inprogress_transition_fires_started_event(): void
    {
        Event::fake([WorkOrderStarted::class]);

        $wo = WorkOrder::factory()->approved()->create();

        $service = $this->app->make(WorkOrderTransitionService::class);
        $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::InProgress,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            tenant_id: $wo->tenant_id,
            company_id: $wo->company_id,
            context: null,
        ));

        Event::assertDispatched(WorkOrderStarted::class);
    }
}
