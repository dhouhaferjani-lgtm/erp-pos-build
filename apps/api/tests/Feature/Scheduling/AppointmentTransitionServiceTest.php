<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Services\AppointmentTransitionService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Events\AppointmentCancelled;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for {@see AppointmentTransitionService}.
 *
 * Ensures:
 *  - the public path rejects system-mirrored targets (InProgress, Completed,
 *    Closed) with a 422-mappable InvalidAppointmentTransitionException,
 *  - the public path allows the non-system edges (Confirmed, Cancelled, etc.),
 *  - the system-mirror path allows system targets (used by Plan B listeners),
 *  - each transition writes an audit row, and Cancelled emits the event.
 */
final class AppointmentTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AppointmentTransitionService::class);
    }

    public function test_public_rejects_in_progress_target(): void
    {
        $appt = Appointment::factory()->checkedIn()->create();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->expectExceptionMessageMatches('/status_reserved_for_system_mirror/');

        $this->service->transitionForPublic($appt->id, AppointmentStatus::InProgress);
    }

    public function test_public_rejects_completed_target(): void
    {
        $appt = Appointment::factory()->checkedIn()->create();
        $appt->status = AppointmentStatus::InProgress;
        $appt->save();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->expectExceptionMessageMatches('/status_reserved_for_system_mirror/');

        $this->service->transitionForPublic($appt->id, AppointmentStatus::Completed);
    }

    public function test_public_rejects_closed_target(): void
    {
        $appt = Appointment::factory()->checkedIn()->create();
        $appt->status = AppointmentStatus::Completed;
        $appt->save();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->expectExceptionMessageMatches('/status_reserved_for_system_mirror/');

        $this->service->transitionForPublic($appt->id, AppointmentStatus::Closed);
    }

    public function test_public_allows_scheduled_to_confirmed(): void
    {
        $appt = Appointment::factory()->create();

        $updated = $this->service->transitionForPublic(
            $appt->id,
            AppointmentStatus::Confirmed,
            triggeredByUserId: null,
        );

        $this->assertSame(AppointmentStatus::Confirmed, $updated->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'from_status' => AppointmentStatus::Scheduled->value,
            'to_status' => AppointmentStatus::Confirmed->value,
        ]);
    }

    public function test_public_allows_cancellation_and_emits_event(): void
    {
        Event::fake([AppointmentCancelled::class]);
        $appt = Appointment::factory()->create();

        $updated = $this->service->transitionForPublic(
            $appt->id,
            AppointmentStatus::Cancelled,
            reasonCode: 'customer_request',
        );

        $this->assertSame(AppointmentStatus::Cancelled, $updated->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'to_status' => AppointmentStatus::Cancelled->value,
            'reason_code' => 'customer_request',
        ]);
        Event::assertDispatched(AppointmentCancelled::class, fn ($e) => $e->appointment_id === $appt->id);
    }

    public function test_public_rejects_illegal_transition(): void
    {
        $appt = Appointment::factory()->create();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->service->transitionForPublic($appt->id, AppointmentStatus::Closed);
    }

    public function test_system_mirror_allows_in_progress_from_checked_in(): void
    {
        $appt = Appointment::factory()->checkedIn()->create();

        $updated = $this->service->transitionForSystemMirror(
            $appt->id,
            AppointmentStatus::InProgress,
        );

        $this->assertSame(AppointmentStatus::InProgress, $updated->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'from_status' => AppointmentStatus::CheckedIn->value,
            'to_status' => AppointmentStatus::InProgress->value,
        ]);
    }

    public function test_system_mirror_rejects_illegal_transition(): void
    {
        $appt = Appointment::factory()->create();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->service->transitionForSystemMirror(
            $appt->id,
            AppointmentStatus::Closed,
        );
    }
}
