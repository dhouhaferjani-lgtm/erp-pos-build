<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Jobs;

use App\Modules\Scheduling\Domain\AppointmentReminder;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Queueable job that actually dispatches a single reminder row.
 *
 * The real SMS/email transport is not wired up in this task — the job
 * marks the row `sent` (with a synthetic `external_id`) so the idempotent
 * scheduling pipeline can be verified end-to-end. When the Notification
 * module lands, the dispatch call can be swapped for a real
 * `Notification::send(...)` without changing the surrounding contract.
 *
 * Skip cases:
 *   - reminder row already non-pending → return silently (another worker
 *     handled it).
 *   - linked appointment is in a terminal state (cancelled / no_show) →
 *     mark row as `skipped`.
 */
final class DispatchAppointmentReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $reminderId,
    ) {}

    public function handle(): void
    {
        /** @var AppointmentReminder|null $reminder */
        $reminder = AppointmentReminder::query()
            ->with('appointment')
            ->find($this->reminderId);

        if ($reminder === null) {
            return;
        }

        if ($reminder->delivery_status !== ReminderDeliveryStatus::Pending) {
            return;
        }

        $appointment = $reminder->appointment()->first();
        if ($appointment === null) {
            return;
        }

        $terminal = [
            AppointmentStatus::Cancelled,
            AppointmentStatus::NoShow,
            AppointmentStatus::Closed,
            AppointmentStatus::Completed,
        ];
        if (in_array($appointment->status, $terminal, true)) {
            $reminder->delivery_status = ReminderDeliveryStatus::Skipped;
            $reminder->save();

            return;
        }

        // Placeholder transport. Real SMS/email is delivered by the (yet to
        // land) Notification module — once available this block will hand
        // off the payload and read back a transport-issued external id.
        $externalId = 'mock-'.substr(bin2hex(random_bytes(8)), 0, 16);

        Log::info('scheduling.reminder.dispatched', [
            'reminder_id' => $reminder->id,
            'appointment_id' => $reminder->appointment_id,
            'channel' => $reminder->channel->value,
        ]);

        $reminder->delivery_status = ReminderDeliveryStatus::Sent;
        $reminder->sent_at = Carbon::now();
        $reminder->external_id = $externalId;
        $reminder->save();
    }
}
