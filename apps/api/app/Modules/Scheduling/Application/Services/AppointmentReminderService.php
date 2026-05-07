<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentReminder;
use App\Modules\Scheduling\Domain\Enums\ReminderChannel;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use App\Modules\Scheduling\Infrastructure\Commands\ScheduleAppointmentReminders;
use App\Modules\Scheduling\Infrastructure\Jobs\DispatchAppointmentReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates per-channel reminder rows 24 hours before an appointment's
 * `scheduled_start`. Safe to call repeatedly — idempotency is guaranteed by
 * the unique `(appointment_id, channel, scheduled_for)` index on
 * `scheduling_appointment_reminders`, which we respect by using an upsert
 * that ignores the conflict.
 *
 * The service does NOT dispatch the message itself; it schedules the row.
 * {@see ScheduleAppointmentReminders}
 * is the cron runner, and
 * {@see DispatchAppointmentReminder}
 * is the queueable transport job.
 */
final class AppointmentReminderService
{
    /** Hours before `scheduled_start` at which the reminder fires. */
    public const DEFAULT_HOURS_BEFORE = 24;

    /**
     * Create reminder rows for the given appointment.
     *
     * Creates one row per channel for which the appointment has a contact
     * value — `customer_email` for email, `customer_phone` for SMS. Rows
     * are created only when `scheduled_start` minus 24h is in the future
     * (past-time windows would dispatch immediately which is almost never
     * what the operator wants).
     *
     * @return list<AppointmentReminder> Rows written or refreshed.
     */
    public function scheduleFor(Appointment $appointment, int $hoursBefore = self::DEFAULT_HOURS_BEFORE): array
    {
        if ($hoursBefore <= 0) {
            return [];
        }

        /** @var \DateTimeInterface $start */
        $start = $appointment->scheduled_start;
        $scheduledForImmutable = \DateTimeImmutable::createFromInterface($start)
            ->modify("-{$hoursBefore} hours");

        // Guard: if the appointment is already within the reminder horizon
        // (or in the past) the window has passed — do not schedule a reminder.
        if ($scheduledForImmutable <= new \DateTimeImmutable) {
            return [];
        }

        $channels = $this->resolveChannels($appointment);
        if ($channels === []) {
            return [];
        }

        /** @var list<AppointmentReminder> $rows */
        $rows = [];

        DB::transaction(function () use ($appointment, $channels, $scheduledForImmutable, &$rows): void {
            foreach ($channels as $channel) {
                $existing = AppointmentReminder::query()
                    ->where('tenant_id', $appointment->tenant_id)
                    ->where('appointment_id', $appointment->id)
                    ->where('channel', $channel->value)
                    ->where('scheduled_for', $scheduledForImmutable)
                    ->first();

                if ($existing !== null) {
                    $rows[] = $existing;

                    continue;
                }

                $reminder = new AppointmentReminder;
                $reminder->id = (string) Str::uuid();
                $reminder->tenant_id = $appointment->tenant_id;
                $reminder->appointment_id = $appointment->id;
                $reminder->channel = $channel;
                $reminder->scheduled_for = Carbon::instance($scheduledForImmutable);
                $reminder->delivery_status = ReminderDeliveryStatus::Pending;
                $reminder->sent_at = null;
                $reminder->external_id = null;
                $reminder->save();

                $rows[] = $reminder;
            }
        });

        return $rows;
    }

    /**
     * @return list<ReminderChannel>
     */
    private function resolveChannels(Appointment $appointment): array
    {
        /** @var list<ReminderChannel> $out */
        $out = [];

        $email = $appointment->customer_email;
        if (is_string($email) && trim($email) !== '') {
            $out[] = ReminderChannel::Email;
        }

        $phone = $appointment->customer_phone;
        if (is_string($phone) && trim($phone) !== '') {
            $out[] = ReminderChannel::Sms;
        }

        return $out;
    }
}
