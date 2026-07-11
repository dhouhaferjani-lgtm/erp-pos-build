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
// A freeze OR a per-repository/per-tenant check failure returns a NON-ZERO
// exit (self::FAILURE) — see ReconcileTreasuryCommand::executeCommand() and
// TenantScopedCommand::forEachTenant(). It must NOT be discarded:
// runInBackground() forks a detached process whose exit code the scheduler does
// not observe in-process, so an ->onFailure() hook could silently never fire —
// a FROZEN drawer would then surface only in audit_events, unnoticed until the
// next drawer op fails. Run in-process so the exit code is observed.
//
// 2026-07-09 audit finding N1: the command's own exit code does NOT
// distinguish "froze on drift" from "a repository/tenant check errored"
// (both collapse to FAILURE, and Laravel's Schedule::onFailure() callback
// has no cheap access to a finer-grained signal than that single exit code)
// — so this alert MUST NOT assert a freeze occurred. It only asserts that
// the run ended non-clean and points the operator at BOTH possible sources
// of truth: the `treasury.reconcile.drift` audit_events (freezes) and the
// application error log (`treasury.reconcile.error` for a per-repository
// failure, or the `TenantScopedCommand::forEachTenant` failure log for a
// per-tenant failure). The command itself already writes the per-repository
// detail; this hook is the proactive top-level signal ops watch.
Schedule::command('treasury:reconcile')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::error('treasury:reconcile exited non-zero — this run either detected drift (one or more payment repositories were FROZEN) or one or more repository/tenant checks failed with an error (left un-frozen), or both; it is NOT known which from this signal alone. Check the treasury.reconcile.drift audit_events for freezes AND the application error log (treasury.reconcile.error / forEachTenant tenant-iteration failures) for check errors before assuming either outcome. A frozen drawer stays locked until an operator clears the freeze; an errored repository was simply skipped this run and should be re-checked.');
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
