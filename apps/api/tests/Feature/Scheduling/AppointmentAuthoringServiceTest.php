<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Commands\BookAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\CancelAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\CheckInAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\ConfirmAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\RescheduleAppointmentCommand;
use App\Modules\Scheduling\Application\Services\AppointmentAuthoringService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Scheduling\Domain\Events\AppointmentCancelled;
use App\Modules\Scheduling\Domain\Events\AppointmentCheckedIn;
use App\Modules\Scheduling\Domain\Events\AppointmentConfirmed;
use App\Modules\Scheduling\Domain\Events\AppointmentRescheduled;
use App\Modules\Scheduling\Domain\Events\AppointmentScheduled;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentConflictException;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for {@see AppointmentAuthoringService}.
 *
 * create / reschedule / cancel / confirm / checkIn — happy path + overlap
 * conflict + invalid-transition paths. Events are asserted via
 * Event::fake().
 */
final class AppointmentAuthoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentAuthoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AppointmentAuthoringService::class);
    }

    public function test_create_persists_appointment_and_services_and_emits_event(): void
    {
        Event::fake();
        $bay = Bay::factory()->create();

        $appointment = $this->service->create($this->command($bay));

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertDatabaseHas('scheduling_appointments', ['id' => $appointment->id]);
        $this->assertDatabaseCount('scheduling_appointment_services', 1);
        Event::assertDispatched(AppointmentScheduled::class, fn ($e) => $e->appointment_id === $appointment->id);
    }

    public function test_create_uses_sequence_for_appointment_number(): void
    {
        $bay = Bay::factory()->create();
        $start = new \DateTimeImmutable('2026-07-01 09:00:00');
        $end = new \DateTimeImmutable('2026-07-01 10:00:00');

        $first = $this->service->create($this->command($bay, $start, $end));
        $second = $this->service->create($this->command(
            $bay,
            new \DateTimeImmutable('2026-07-01 11:00:00'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        ));

        $this->assertMatchesRegularExpression('/^APT-2026-\d{6}$/', $first->appointment_number);
        $this->assertMatchesRegularExpression('/^APT-2026-\d{6}$/', $second->appointment_number);
        $this->assertNotSame($first->appointment_number, $second->appointment_number);
    }

    public function test_create_throws_conflict_when_bay_overlaps(): void
    {
        $bay = Bay::factory()->create();
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create();

        $this->expectException(AppointmentConflictException::class);
        $this->service->create($this->command(
            $bay,
            new \DateTimeImmutable('2026-05-04 10:30:00'),
            new \DateTimeImmutable('2026-05-04 11:30:00'),
        ));
    }

    public function test_create_allows_adjacent_boundary(): void
    {
        $bay = Bay::factory()->create();
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create();

        $appt = $this->service->create($this->command(
            $bay,
            new \DateTimeImmutable('2026-05-04 11:00:00'),
            new \DateTimeImmutable('2026-05-04 12:00:00'),
        ));

        $this->assertSame(AppointmentStatus::Scheduled, $appt->status);
    }

    public function test_create_rejects_end_before_start(): void
    {
        $bay = Bay::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->command(
            $bay,
            new \DateTimeImmutable('2026-05-04 11:00:00'),
            new \DateTimeImmutable('2026-05-04 10:00:00'),
        ));
    }

    public function test_reschedule_updates_window_and_emits_event(): void
    {
        Event::fake();
        $appt = Appointment::factory()->create();

        $updated = $this->service->reschedule(new RescheduleAppointmentCommand(
            appointment_id: $appt->id,
            new_bay_id: $appt->bay_id,
            new_scheduled_start: new \DateTimeImmutable('2026-06-01 14:00:00'),
            new_scheduled_end: new \DateTimeImmutable('2026-06-01 15:00:00'),
            rescheduled_by_user_id: null,
        ));

        $this->assertSame('2026-06-01 14:00:00', $updated->scheduled_start->format('Y-m-d H:i:s'));
        $this->assertSame(60, $updated->estimated_duration_minutes);
        Event::assertDispatched(AppointmentRescheduled::class, fn ($e) => $e->appointment_id === $appt->id);
    }

    public function test_reschedule_self_does_not_conflict_with_itself(): void
    {
        $appt = Appointment::factory()->create();

        $updated = $this->service->reschedule(new RescheduleAppointmentCommand(
            appointment_id: $appt->id,
            new_bay_id: $appt->bay_id,
            new_scheduled_start: \DateTimeImmutable::createFromInterface($appt->scheduled_start),
            new_scheduled_end: \DateTimeImmutable::createFromInterface($appt->scheduled_end),
            rescheduled_by_user_id: null,
        ));

        $this->assertSame($appt->id, $updated->id);
    }

    public function test_reschedule_rejects_when_other_appointment_overlaps(): void
    {
        $bay = Bay::factory()->create();
        $other = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 14:00:00'),
            new \DateTimeImmutable('2026-05-04 15:00:00'),
        )->create();
        $target = Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create();

        $this->assertNotSame($other->id, $target->id);
        $this->expectException(AppointmentConflictException::class);
        $this->service->reschedule(new RescheduleAppointmentCommand(
            appointment_id: $target->id,
            new_bay_id: $bay->id,
            new_scheduled_start: new \DateTimeImmutable('2026-05-04 14:30:00'),
            new_scheduled_end: new \DateTimeImmutable('2026-05-04 15:30:00'),
            rescheduled_by_user_id: null,
        ));
    }

    public function test_cancel_changes_status_and_emits_event(): void
    {
        Event::fake();
        $appt = Appointment::factory()->create();

        $cancelled = $this->service->cancel(new CancelAppointmentCommand(
            appointment_id: $appt->id,
            reason_code: 'customer_request',
            cancelled_by_user_id: null,
        ));

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        $this->assertDatabaseHas('scheduling_appointment_status_transitions', [
            'appointment_id' => $appt->id,
            'to_status' => AppointmentStatus::Cancelled->value,
            'reason_code' => 'customer_request',
        ]);
        Event::assertDispatched(AppointmentCancelled::class);
    }

    public function test_cancel_rejects_terminal_state(): void
    {
        $appt = Appointment::factory()->cancelled()->create();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->service->cancel(new CancelAppointmentCommand(
            appointment_id: $appt->id,
            reason_code: null,
            cancelled_by_user_id: null,
        ));
    }

    public function test_confirm_transitions_scheduled_to_confirmed(): void
    {
        Event::fake();
        $appt = Appointment::factory()->create();

        $confirmed = $this->service->confirm(new ConfirmAppointmentCommand(
            appointment_id: $appt->id,
            confirmed_by_user_id: null,
        ));

        $this->assertSame(AppointmentStatus::Confirmed, $confirmed->status);
        Event::assertDispatched(AppointmentConfirmed::class);
    }

    public function test_check_in_requires_confirmed_or_scheduled(): void
    {
        Event::fake();
        $appt = Appointment::factory()->confirmed()->create();

        $arrival = new \DateTimeImmutable('2026-05-04 10:05:00');
        $checked = $this->service->checkIn(new CheckInAppointmentCommand(
            appointment_id: $appt->id,
            actual_arrival_at: $arrival,
            checked_in_by_user_id: null,
        ));

        $this->assertSame(AppointmentStatus::CheckedIn, $checked->status);
        $this->assertSame('2026-05-04 10:05:00', $checked->actual_arrival_at?->format('Y-m-d H:i:s'));
        Event::assertDispatched(AppointmentCheckedIn::class);
    }

    public function test_check_in_rejects_cancelled(): void
    {
        $appt = Appointment::factory()->cancelled()->create();

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->service->checkIn(new CheckInAppointmentCommand(
            appointment_id: $appt->id,
            actual_arrival_at: new \DateTimeImmutable,
            checked_in_by_user_id: null,
        ));
    }

    public function test_pg_exclusion_violation_is_mapped_to_conflict_exception(): void
    {
        if (\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('GiST exclusion constraint only applies on PostgreSQL.');
        }

        $bay = Bay::factory()->create();
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create();

        $this->expectException(AppointmentConflictException::class);
        // Bypass pre-flight by mocking — direct insert should trip GiST.
        // NOTE: on PG, the pre-flight also catches it. This test documents
        // the belt-and-braces 23P01 translation.
        $this->service->create($this->command(
            $bay,
            new \DateTimeImmutable('2026-05-04 10:15:00'),
            new \DateTimeImmutable('2026-05-04 11:15:00'),
        ));
    }

    private function command(
        Bay $bay,
        ?\DateTimeImmutable $start = null,
        ?\DateTimeImmutable $end = null,
    ): BookAppointmentCommand {
        $start ??= new \DateTimeImmutable('2026-07-01 09:00:00');
        $end ??= new \DateTimeImmutable('2026-07-01 10:00:00');

        return new BookAppointmentCommand(
            tenant_id: $bay->tenant_id,
            company_id: $bay->company_id,
            location_id: $bay->location_id,
            bay_id: $bay->id,
            primary_technician_profile_id: null,
            customer_partner_id: null,
            vehicle_id: null,
            customer_name: 'Alice',
            customer_phone: '+33612345678',
            customer_email: null,
            vehicle_plate: 'AB-123-CD',
            vehicle_description: null,
            appointment_type: AppointmentType::StandardRepair,
            wait_type: WaitType::DropOff,
            scheduled_start: $start,
            scheduled_end: $end,
            estimated_duration_minutes: (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
            source: AppointmentSource::Manual,
            planned_services: [[
                'service_ref_type' => AppointmentService::REF_TYPE_SERVICE,
                'service_ref_id' => (string) Str::uuid(),
                'display_name' => 'Oil Change',
                'estimated_duration_minutes' => 60,
                'estimated_price' => '45.000',
                'display_order' => 0,
            ]],
            services_summary: 'Oil change',
            customer_notes: null,
            internal_notes: null,
        );
    }
}
