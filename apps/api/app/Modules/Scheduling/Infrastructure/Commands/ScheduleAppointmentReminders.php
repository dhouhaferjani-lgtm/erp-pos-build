<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Commands;

use App\Modules\Scheduling\Application\Services\AppointmentReminderService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentReminder;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use App\Modules\Scheduling\Infrastructure\Jobs\DispatchAppointmentReminder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Hourly scheduled command.
 *
 *   1. Finds upcoming appointments (next 48 hours) lacking a reminder row
 *      and schedules them via {@see AppointmentReminderService::scheduleFor}.
 *   2. Finds every already-scheduled reminder whose `scheduled_for` has
 *      elapsed but whose `delivery_status` is still `pending`, and
 *      dispatches the queueable delivery job.
 *
 * Follows the shape established by `CheckExpiringCertifications` (Plan C):
 * a thin console command that delegates the heavy lifting to a domain
 * service / queueable job so the command itself stays test-friendly.
 */
final class ScheduleAppointmentReminders extends Command
{
    /** @var string */
    protected $signature = 'scheduling:schedule-appointment-reminders {--horizon-hours=48}';

    /** @var string */
    protected $description = 'Schedule and dispatch 24h appointment reminders (email + SMS, per-channel idempotent).';

    public function __construct(
        private readonly AppointmentReminderService $reminders,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var int $horizonHours */
        $horizonHours = (int) $this->option('horizon-hours');
        if ($horizonHours <= 0) {
            $this->error('--horizon-hours must be a positive integer.');

            return self::INVALID;
        }

        $scheduled = $this->scheduleUpcoming($horizonHours);
        $dispatched = $this->dispatchDueReminders();

        $this->info(sprintf(
            'Scheduled %d reminder rows; dispatched %d pending reminders.',
            $scheduled,
            $dispatched,
        ));

        return self::SUCCESS;
    }

    private function scheduleUpcoming(int $horizonHours): int
    {
        $now = Carbon::now();
        $horizon = $now->copy()->addHours($horizonHours);

        /** @var Collection<int, Appointment> $upcoming */
        $upcoming = Appointment::query()
            ->whereIn('status', [
                AppointmentStatus::Scheduled->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::CheckedIn->value,
            ])
            ->whereBetween('scheduled_start', [$now, $horizon])
            ->get();

        $count = 0;
        foreach ($upcoming as $appointment) {
            $rows = $this->reminders->scheduleFor($appointment);
            $count += count($rows);
        }

        return $count;
    }

    private function dispatchDueReminders(): int
    {
        $now = Carbon::now();

        /** @var Collection<int, AppointmentReminder> $due */
        $due = AppointmentReminder::query()
            ->where('delivery_status', ReminderDeliveryStatus::Pending->value)
            ->where('scheduled_for', '<=', $now)
            ->get();

        foreach ($due as $reminder) {
            DispatchAppointmentReminder::dispatch($reminder->id);
        }

        return $due->count();
    }
}
