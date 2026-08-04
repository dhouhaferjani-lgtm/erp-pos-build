<?php

use App\Modules\Accounting\Presentation\Console\CheckSubledgerReconciliationCommand;
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
// The command's exit code does NOT distinguish a cash freeze, alert-only
// portfolio drift, or a repository/company/tenant check error (all collapse to
// FAILURE, and Laravel's Schedule::onFailure() callback
// has no cheap access to a finer-grained signal than that single exit code)
// — so this alert MUST NOT assert a freeze occurred. It only asserts that
// the run ended non-clean and points the operator at every possible source
// of truth: `treasury.reconcile.drift` audit_events (cash freezes),
// `treasury.reconcile.portfolio_drift` audit_events (alert-only GL mismatch),
// and the application error log (`treasury.reconcile.error` for a per-resource
// failure, or the `TenantScopedCommand::forEachTenant` failure log for a
// per-tenant failure). The command itself already writes the per-repository
// detail; this hook is the proactive top-level signal ops watch.
Schedule::command('treasury:reconcile')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::error('treasury:reconcile exited non-zero — this run detected cash drift (one or more payment repositories were FROZEN), alert-only portfolio/GL drift (NO repository freeze), one or more repository/company/tenant check errors, or a combination; it is NOT known which from this signal alone. Check treasury.reconcile.drift audit events for cash freezes, treasury.reconcile.portfolio_drift audit events for portfolio mismatch, and the application error log (treasury.reconcile.error / forEachTenant failures) for check errors. A frozen drawer stays locked until an operator clears the freeze; portfolio drift and errored resources remain unfrozen and require investigation/recheck.');
    });

// Schedule: alert on instruments approaching remittance or overdue settlement.
// Run in-process so the scheduler observes the command's partial-failure exit.
Schedule::command('treasury:instrument-maturity-alerts')
    ->dailyAt('06:30')
    ->withoutOverlapping();

// Schedule: materialize recurring expense drafts + due reminders.
// Run in-process so the scheduler observes the command's partial-failure exit.
Schedule::command('expenses:generate-recurring')
    ->dailyAt('05:30')
    ->withoutOverlapping();

// Schedule: Expire old stock reservations every 15 minutes.
//
// Was `Schedule::job(ExpireReservationsJob::class)` until 2026-08-04. That queue
// job ran the sweep from CENTRAL context, so under database-per-tenant every
// tick died with `relation "stock_reservations" does not exist` (4,211 central
// failed_jobs rows). `inventory:expire-reservations` iterates tenants
// explicitly via TenantScopedCommand::forEachTenant().
//
// Run in-process (no runInBackground()).
//
// NOTE — do NOT justify this with "onFailure() would not fire": it fires in
// BOTH modes. A background event's forked process re-invokes `schedule:finish`,
// whose handle() calls Event::finish() -> callAfterCallbacks() with the child's
// exit code, and onFailure() is exactly such an after-callback gated on
// `exitCode !== 0` (Event.php:706-718, ScheduleFinishCommand.php:41-49).
// The real reasons for foreground here: the exit code is observed inline in the
// scheduler process, so failure signalling does not depend on the forked child
// surviving long enough to re-invoke `schedule:finish` — a child that is killed
// (OOM, deploy restart, reboot) leaves the after-callbacks uncalled AND its
// overlap mutex to expire on its own timer.
// Trade-off, accepted: a slow sweep serialises this 15-minute scheduler tick
// until it finishes.
//
// withoutOverlapping(30) caps that mutex at 30 minutes — two ticks — instead of
// the bare 1440-minute default, which would silently skip a full day of sweeps
// after one crashed run.
Schedule::command('inventory:expire-reservations')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onFailure(function (): void {
        Log::error('inventory:expire-reservations exited non-zero — one or more tenants failed their stock-reservation expiry sweep (expired reservations stay ACTIVE and their reserved quantity stays locked out of available stock until a later run succeeds). Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
    });

// Schedule: Check for expired batches daily at 1:30 AM.
//
// Was `Schedule::job(DailyExpiryCheck::class)` until 2026-08-04. That queue job
// queried product_batches from CENTRAL context, so under database-per-tenant it
// failed every night and retried to MaxAttemptsExceeded.
// `batch-expiry:daily-check` iterates tenants explicitly via
// TenantScopedCommand::forEachTenant().
//
// Run in-process (no runInBackground()) for the same reason as the entry above:
// onFailure() WOULD still fire when backgrounded (schedule:finish ->
// Event::finish() -> callAfterCallbacks(), exit-code gated), but foreground
// observes the exit code inline instead of relying on the forked child living
// long enough to re-invoke `schedule:finish`. This runs once a night at 01:30,
// so serialising that tick costs nothing. The hook below is also the sysadmin
// alert that the deleted job's `failed()` method only ever left as a TODO.
Schedule::command('batch-expiry:daily-check')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::error('batch-expiry:daily-check exited non-zero — one or more tenants failed their nightly batch expiry sweep. For those tenants, expired batches were NOT flagged is_expired (FEFO can still pick them) and admins got NO critical-expiry (7-day) alert. Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
    });

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
