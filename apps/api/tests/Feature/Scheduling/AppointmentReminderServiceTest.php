<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Services\AppointmentReminderService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentReminder;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\ReminderChannel;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use App\Modules\Scheduling\Infrastructure\Commands\ScheduleAppointmentReminders;
use App\Modules\Scheduling\Infrastructure\Jobs\DispatchAppointmentReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 14 — reminder scheduling + dispatch tests.
 *
 * Covers:
 *  - scheduleFor() creates one row per contact channel (email + SMS).
 *  - Calling scheduleFor() twice does NOT create duplicate rows
 *    (per-channel idempotency guaranteed by unique index).
 *  - Null / empty contact info skips that channel.
 *  - Appointments beyond the reminder horizon get no row.
 *  - DispatchAppointmentReminder stamps sent_at + flips delivery_status.
 *  - Terminal-status appointment → reminder marked `skipped`.
 *  - Scheduled command dispatches a queueable job for each due pending row.
 */
final class AppointmentReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AppointmentReminderService::class);
    }

    public function test_schedule_for_creates_one_row_per_channel_when_both_contacts_present(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+21611223344',
        ]);

        $rows = $this->service->scheduleFor($appt);

        $this->assertCount(2, $rows);
        $this->assertDatabaseCount('scheduling_appointment_reminders', 2);

        $channels = array_map(
            static fn (AppointmentReminder $r): string => $r->channel->value,
            $rows,
        );
        $this->assertContains(ReminderChannel::Email->value, $channels);
        $this->assertContains(ReminderChannel::Sms->value, $channels);
    }

    public function test_schedule_for_is_idempotent_on_repeated_calls(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+21611223344',
        ]);

        $first = $this->service->scheduleFor($appt);
        $second = $this->service->scheduleFor($appt);

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertDatabaseCount('scheduling_appointment_reminders', 2);

        foreach ($second as $row) {
            $this->assertNotSame('', $row->id);
        }
    }

    public function test_schedule_for_skips_channel_when_contact_value_is_null(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => null,
            'customer_phone' => '+21611223344',
        ]);

        $rows = $this->service->scheduleFor($appt);

        $this->assertCount(1, $rows);
        $this->assertSame(ReminderChannel::Sms, $rows[0]->channel);
    }

    public function test_schedule_for_returns_no_rows_when_no_contact_info(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => null,
            'customer_phone' => null,
        ]);

        $rows = $this->service->scheduleFor($appt);

        $this->assertSame([], $rows);
        $this->assertDatabaseCount('scheduling_appointment_reminders', 0);
    }

    public function test_schedule_for_skips_when_start_is_within_reminder_window(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            // Only 12 hours away — reminder window (24h before) already passed.
            'scheduled_start' => Carbon::now()->addHours(12),
            'scheduled_end' => Carbon::now()->addHours(13),
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+21611223344',
        ]);

        $rows = $this->service->scheduleFor($appt);

        $this->assertSame([], $rows);
        $this->assertDatabaseCount('scheduling_appointment_reminders', 0);
    }

    public function test_dispatch_job_marks_reminder_sent_and_records_external_id(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => 'customer@example.com',
            'customer_phone' => '+21611223344',
        ]);
        $rows = $this->service->scheduleFor($appt);
        $this->assertCount(2, $rows);

        $first = $rows[0];
        (new DispatchAppointmentReminder($first->id))->handle();

        /** @var AppointmentReminder $refreshed */
        $refreshed = AppointmentReminder::query()->findOrFail($first->id);
        $this->assertSame(ReminderDeliveryStatus::Sent, $refreshed->delivery_status);
        $this->assertNotNull($refreshed->sent_at);
        $this->assertNotNull($refreshed->external_id);
    }

    public function test_dispatch_job_marks_skipped_when_appointment_already_cancelled(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => 'customer@example.com',
            'customer_phone' => null,
        ]);
        $rows = $this->service->scheduleFor($appt);
        $this->assertCount(1, $rows);

        $appt->status = AppointmentStatus::Cancelled;
        $appt->save();

        (new DispatchAppointmentReminder($rows[0]->id))->handle();

        /** @var AppointmentReminder $refreshed */
        $refreshed = AppointmentReminder::query()->findOrFail($rows[0]->id);
        $this->assertSame(ReminderDeliveryStatus::Skipped, $refreshed->delivery_status);
        $this->assertNull($refreshed->sent_at);
    }

    public function test_dispatch_job_is_idempotent_on_non_pending_rows(): void
    {
        /** @var Appointment $appt */
        $appt = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => 'customer@example.com',
            'customer_phone' => null,
        ]);
        $rows = $this->service->scheduleFor($appt);
        $reminder = $rows[0];

        (new DispatchAppointmentReminder($reminder->id))->handle();
        $firstSentAt = AppointmentReminder::query()->findOrFail($reminder->id)->sent_at;

        // Running twice must NOT re-stamp sent_at.
        (new DispatchAppointmentReminder($reminder->id))->handle();
        $secondSentAt = AppointmentReminder::query()->findOrFail($reminder->id)->sent_at;

        $this->assertNotNull($firstSentAt);
        $this->assertNotNull($secondSentAt);
        $this->assertSame(
            $firstSentAt->toDateTimeString(),
            $secondSentAt->toDateTimeString(),
        );
    }

    public function test_scheduled_command_schedules_rows_and_dispatches_pending_due_reminders(): void
    {
        Queue::fake();

        // Future appointment — command schedules fresh rows (not yet due).
        /** @var Appointment $future */
        $future = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(30),
            'scheduled_end' => Carbon::now()->addHours(31),
            'customer_email' => 'future@example.com',
            'customer_phone' => null,
        ]);

        // Already-pending reminder whose scheduled_for is due — dispatch path.
        /** @var Appointment $due */
        $due = Appointment::factory()->create([
            'scheduled_start' => Carbon::now()->addHours(24),
            'scheduled_end' => Carbon::now()->addHours(25),
            'customer_email' => 'due@example.com',
            'customer_phone' => null,
        ]);
        $duePast = Carbon::now()->subMinutes(5);
        AppointmentReminder::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $due->tenant_id,
            'appointment_id' => $due->id,
            'channel' => ReminderChannel::Email->value,
            'scheduled_for' => $duePast,
            'delivery_status' => ReminderDeliveryStatus::Pending->value,
        ]);

        $exit = Artisan::call(ScheduleAppointmentReminders::class);
        $this->assertSame(0, $exit);

        // The future appointment had its email reminder scheduled (1 row).
        $this->assertDatabaseHas('scheduling_appointment_reminders', [
            'appointment_id' => $future->id,
            'channel' => ReminderChannel::Email->value,
            'delivery_status' => ReminderDeliveryStatus::Pending->value,
        ]);

        // The due reminder's dispatch job was queued.
        Queue::assertPushed(DispatchAppointmentReminder::class, 1);
    }
}
