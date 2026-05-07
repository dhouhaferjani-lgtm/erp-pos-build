<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Application\Services\AppointmentReminderService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentReminder;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\ReminderDeliveryStatus;
use App\Modules\Scheduling\Infrastructure\Jobs\DispatchAppointmentReminder;
use App\Modules\Tenant\Domain\Tenant;
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
 * Tenant-isolation: cat-(a-per-tenant-iter). Per master plan §14 invariant 2,
 * the scheduler MUST iterate explicitly per tenant; it MUST NOT issue a
 * single cross-tenant query. The previous implementation queried
 * `Appointment::query()` and `AppointmentReminder::query()` directly across
 * all tenants — that's been replaced with {@see TenantScopedCommand::forEachTenant()}
 * wrapping per-tenant `where('tenant_id', …)` reads. The dispatched
 * {@see DispatchAppointmentReminder} job receives the reminder's tenant_id
 * as a constructor arg and re-asserts the scope on its own first read so the
 * defense-in-depth holds even if the queue worker runs without a context.
 */
final class ScheduleAppointmentReminders extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'scheduling:schedule-appointment-reminders {--horizon-hours=48}';

    /** @var string */
    protected $description = 'Schedule and dispatch 24h appointment reminders (email + SMS, per-channel idempotent).';

    public function __construct(
        CompanyContext $companyContext,
        private readonly AppointmentReminderService $reminders,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        /** @var int $horizonHours */
        $horizonHours = (int) $this->option('horizon-hours');
        if ($horizonHours <= 0) {
            $this->error('--horizon-hours must be a positive integer.');

            return self::INVALID;
        }

        $totalScheduled = 0;
        $totalDispatched = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use ($horizonHours, &$totalScheduled, &$totalDispatched): int {
            $totalScheduled += $this->scheduleUpcomingForTenant($tenant->id, $horizonHours);
            $totalDispatched += $this->dispatchDueRemindersForTenant($tenant->id);

            return self::SUCCESS;
        });

        $this->info(sprintf(
            'Scheduled %d reminder rows; dispatched %d pending reminders.',
            $totalScheduled,
            $totalDispatched,
        ));

        return $exit;
    }

    private function scheduleUpcomingForTenant(string $tenantId, int $horizonHours): int
    {
        $now = Carbon::now();
        $horizon = $now->copy()->addHours($horizonHours);

        /** @var Collection<int, Appointment> $upcoming */
        $upcoming = Appointment::query()
            ->where('tenant_id', $tenantId)
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

    private function dispatchDueRemindersForTenant(string $tenantId): int
    {
        $now = Carbon::now();

        /** @var Collection<int, AppointmentReminder> $due */
        $due = AppointmentReminder::query()
            ->where('tenant_id', $tenantId)
            ->where('delivery_status', ReminderDeliveryStatus::Pending->value)
            ->where('scheduled_for', '<=', $now)
            ->get();

        foreach ($due as $reminder) {
            DispatchAppointmentReminder::dispatch($reminder->id, $reminder->tenant_id);
        }

        return $due->count();
    }
}
