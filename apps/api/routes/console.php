<?php

use App\Modules\Accounting\Presentation\Console\CheckSubledgerReconciliationCommand;
use App\Modules\BatchExpiry\Jobs\DailyExpiryCheck;
use App\Modules\Inventory\Application\Jobs\ExpireReservationsJob;
use App\Modules\Scheduling\Infrastructure\Commands\ScheduleAppointmentReminders;
use App\Modules\Workshop\Technician\Infrastructure\Commands\CheckExpiringCertifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: Lock expired fiscal periods daily at 1:00 AM
Schedule::command('fiscal:lock-expired-periods')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Retry exhausted/dead-lettered fiscal projections every 15 minutes
Schedule::command('fiscal:retry-projections --limit=100')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Fraud pattern detection daily at 2:00 AM
Schedule::command('fraud:detect')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Expire old stock reservations every 15 minutes
Schedule::job(ExpireReservationsJob::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Schedule: Check for expired batches daily at 1:30 AM
Schedule::job(DailyExpiryCheck::class)
    ->dailyAt('01:30')
    ->withoutOverlapping();

// Schedule: Poll platform for pending enrichment status updates
Schedule::command('enrichment:check-pending')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Schedule: Partner subledger/control-account reconciliation alert daily at 2:30 AM
Schedule::command(CheckSubledgerReconciliationCommand::class)
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Dispatch TechnicianCertificationExpiring events daily at 3:00 AM
Schedule::command(CheckExpiringCertifications::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Schedule: Appointment reminder scheduling + dispatch every hour
Schedule::command(ScheduleAppointmentReminders::class)
    ->hourly()
    ->withoutOverlapping();
