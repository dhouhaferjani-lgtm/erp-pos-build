<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderCancelled;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderClosed;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderCompleted;
use App\Modules\Scheduling\Infrastructure\Listeners\MirrorAppointmentOnWorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCancelled;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderClosed;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderStarted;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the 4 MirrorAppointmentOnWorkOrder* listeners.
 *
 * Dispatches each Plan B WorkOrder lifecycle event for an appointment linked
 * via `work_order_id` and asserts the appointment status reflects the
 * authorized system-mirror target.
 *
 * Also verifies silent no-op when no appointment is linked (walk-in WO).
 */
final class MirrorWorkOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_started_mirrors_to_in_progress(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->checkedIn()->create([
            'work_order_id' => $workOrder->id,
        ]);

        Event::dispatch(new WorkOrderStarted(
            work_order_id: $workOrder->id,
            primary_technician_profile_id: '',
            started_at: new \DateTimeImmutable,
        ));

        $appt->refresh();
        $this->assertSame(AppointmentStatus::InProgress, $appt->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'to_status' => AppointmentStatus::InProgress->value,
            'reason_code' => 'work_order_started',
        ]);
    }

    public function test_work_order_completed_mirrors_to_completed(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->create([
            'status' => AppointmentStatus::InProgress->value,
            'work_order_id' => $workOrder->id,
        ]);

        Event::dispatch(new WorkOrderCompleted(
            work_order_id: $workOrder->id,
            completion_mileage: null,
            completed_at: new \DateTimeImmutable,
        ));

        $appt->refresh();
        $this->assertSame(AppointmentStatus::Completed, $appt->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'to_status' => AppointmentStatus::Completed->value,
            'reason_code' => 'work_order_completed',
        ]);
    }

    public function test_work_order_cancelled_mirrors_to_cancelled_with_reason(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->create([
            'status' => AppointmentStatus::InProgress->value,
            'work_order_id' => $workOrder->id,
        ]);

        Event::dispatch(new WorkOrderCancelled(
            work_order_id: $workOrder->id,
            reason_code: 'customer_declined_repair',
            cancelled_at: new \DateTimeImmutable,
        ));

        $appt->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appt->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'to_status' => AppointmentStatus::Cancelled->value,
            'reason_code' => 'customer_declined_repair',
        ]);
    }

    public function test_work_order_closed_mirrors_to_closed(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->create([
            'status' => AppointmentStatus::Completed->value,
            'work_order_id' => $workOrder->id,
        ]);

        Event::dispatch(new WorkOrderClosed(
            work_order_id: $workOrder->id,
            closed_at: new \DateTimeImmutable,
        ));

        $appt->refresh();
        $this->assertSame(AppointmentStatus::Closed, $appt->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'to_status' => AppointmentStatus::Closed->value,
            'reason_code' => 'work_order_closed',
        ]);
    }

    public function test_walkin_work_order_without_appointment_is_silent_no_op(): void
    {
        $unlinkedWorkOrderId = (string) Str::uuid();

        // Dispatch all 4 events — none should blow up.
        Event::dispatch(new WorkOrderStarted(
            work_order_id: $unlinkedWorkOrderId,
            primary_technician_profile_id: '',
            started_at: new \DateTimeImmutable,
        ));
        Event::dispatch(new WorkOrderCompleted(
            work_order_id: $unlinkedWorkOrderId,
            completion_mileage: null,
            completed_at: new \DateTimeImmutable,
        ));
        Event::dispatch(new WorkOrderCancelled(
            work_order_id: $unlinkedWorkOrderId,
            reason_code: 'no_show',
            cancelled_at: new \DateTimeImmutable,
        ));
        Event::dispatch(new WorkOrderClosed(
            work_order_id: $unlinkedWorkOrderId,
            closed_at: new \DateTimeImmutable,
        ));

        $this->assertDatabaseCount('scheduling_appointment_status_transitions', 0);
    }

    public function test_repeated_events_are_idempotent(): void
    {
        $workOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->checkedIn()->create([
            'work_order_id' => $workOrder->id,
        ]);

        Event::dispatch(new WorkOrderStarted(
            work_order_id: $workOrder->id,
            primary_technician_profile_id: '',
            started_at: new \DateTimeImmutable,
        ));
        Event::dispatch(new WorkOrderStarted(
            work_order_id: $workOrder->id,
            primary_technician_profile_id: '',
            started_at: new \DateTimeImmutable,
        ));

        $appt->refresh();
        $this->assertSame(AppointmentStatus::InProgress, $appt->status);
        // Only one transition row; the second dispatch is short-circuited.
        $this->assertSame(
            1,
            \DB::table('scheduling_appointment_status_transitions')
                ->where('appointment_id', $appt->id)
                ->count(),
        );
    }

    public function test_event_service_provider_registers_all_four_listeners(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $this->assertContains(
            MirrorAppointmentOnWorkOrderStarted::class,
            array_map('strval', $dispatcher->getRawListeners()[WorkOrderStarted::class] ?? []),
        );
        $this->assertContains(
            MirrorAppointmentOnWorkOrderCompleted::class,
            array_map('strval', $dispatcher->getRawListeners()[WorkOrderCompleted::class] ?? []),
        );
        $this->assertContains(
            MirrorAppointmentOnWorkOrderCancelled::class,
            array_map('strval', $dispatcher->getRawListeners()[WorkOrderCancelled::class] ?? []),
        );
        $this->assertContains(
            MirrorAppointmentOnWorkOrderClosed::class,
            array_map('strval', $dispatcher->getRawListeners()[WorkOrderClosed::class] ?? []),
        );
    }
}
