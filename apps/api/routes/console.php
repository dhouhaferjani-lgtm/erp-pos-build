<?php

use App\Modules\Accounting\Presentation\Console\CheckSubledgerReconciliationCommand;
use App\Modules\BatchExpiry\Jobs\DailyExpiryCheck;
use App\Modules\Inventory\Application\Jobs\ExpireReservationsJob;
use App\Modules\Scheduling\Infrastructure\Commands\ScheduleAppointmentReminders;
use App\Modules\Workshop\Technician\Infrastructure\Commands\CheckExpiringCertifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

// Schedule: Treasury reconciliation (balance/ledger/GL/transfer coherence) —
// freeze-on-drift, never repair — daily at 2:15 AM.
//
// A freeze returns a NON-ZERO exit (self::FAILURE). It must NOT be discarded:
// runInBackground() forks a detached process whose exit code the scheduler does
// not observe in-process, so an ->onFailure() hook could silently never fire —
// a FROZEN drawer would then surface only in audit_events, unnoticed until the
// next drawer op fails. Run in-process so the exit code is observed, and raise
// an error-level alert on any freeze (Task-24 fix B). The command itself already
// writes the per-repository detail (error log + treasury.reconcile.drift audit
// row); this hook is the proactive top-level signal ops watch.
Schedule::command('treasury:reconcile')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::error('treasury:reconcile reported drift — one or more payment repositories were FROZEN. Investigate the treasury.reconcile.drift audit_events immediately; the drawer stays locked until an operator clears the freeze.');
    });

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
