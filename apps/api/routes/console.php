<?php

use App\Modules\Accounting\Presentation\Console\CheckCogsCoverageCommand;
use App\Modules\Accounting\Presentation\Console\CheckSubledgerReconciliationCommand;
use App\Modules\Scheduling\Infrastructure\Commands\ScheduleAppointmentReminders;
use App\Modules\Workshop\Technician\Infrastructure\Commands\CheckExpiringCertifications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::command('support-access:expire --limit=1000')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(function (): void {
        Log::error('support-access:expire exited non-zero; elapsed grants remain fail-closed at request time, but lifecycle audit/session cleanup must be reconciled.');
    });

Schedule::command('support-access:audit-reconcile --limit=1000')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(function (): void {
        Log::error('support-access:audit-reconcile exited non-zero; one or more durable admin/tenant audit mirrors remain pending.');
    });

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: Lock expired fiscal periods daily at 1:00 AM.
//
// Until 2026-08-05 this command called FiscalPeriodAutoLockService ONCE on the
// scheduler's connection — CENTRAL under database-per-tenant, where
// fiscal_periods / fiscal_years do not exist. The resulting 42P01 was swallowed
// by a catch(\Exception) inside the command AND the exit code was thrown away
// by runInBackground() with no ->onFailure(), so the nightly auto-lock had been
// silently dead since the 2026-05-28 flip. It now iterates tenants explicitly
// via TenantScopedCommand::forEachTenant() and no longer swallows.
//
// Run in-process (no runInBackground()) for the reason recorded on the entries
// below: onFailure() fires in both modes, but foreground observes the exit code
// inline instead of depending on the forked child surviving long enough to
// re-invoke `schedule:finish`. Ordering: batch-expiry:daily-check is at 01:30 —
// keep that gap.
//
// withoutOverlapping(720) — see the sizing note above `fraud:detect` below.
// 12 h is well beyond any plausible fleet-wide auto-lock, and half the 24 h
// gap to the next run, so a crashed process can never swallow a whole night.
Schedule::command('fiscal:lock-expired-periods')
    ->dailyAt('01:00')
    ->withoutOverlapping(720)
    ->onFailure(function (): void {
        Log::error('fiscal:lock-expired-periods exited non-zero — one or more tenants failed their nightly fiscal auto-lock. For those tenants, periods that ended past the country threshold were NOT moved Open -> Closed, ended fiscal years were NOT marked closed, and periods inside closed years were NOT Locked — so postings can still land in a period the law considers shut. Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
    });

// Schedule: Retry exhausted/dead-lettered fiscal projections every 15 minutes
Schedule::command('fiscal:retry-projections --limit=100')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Schedule: Fraud pattern detection daily at 2:00 AM.
//
// Until 2026-08-05 handle() opened with `Company::all()` OUTSIDE the per-company
// try/catch. `companies` is a TENANT table, so under database-per-tenant the
// 42P01 escaped the whole command — and runInBackground() with no ->onFailure()
// threw the exit code away. `fraud:detect` now iterates tenants explicitly via
// TenantScopedCommand::forEachTenant() and enumerates companies inside the
// tenant's own database. Run in-process so the exit code is observed inline.
//
// SIZING THE MUTEX (R4, 2026-08-05 wave-1 review — the reference note for the
// daily entries in this file). `withoutOverlapping($n)` is the mutex's EXPIRY,
// not a runtime cap: nothing kills a run at $n minutes. Past $n the lock simply
// evaporates and the NEXT tick starts CONCURRENTLY. So $n has to sit above the
// worst-case fleet runtime (below it, overlap; and here overlap means duplicate
// fraud alerts and duplicate fraud-triggered stock counting) and below the
// cadence (above it, one crashed process swallows the following run entirely —
// which the bare 1440-minute default does exactly, since it equals the dailyAt()
// gap).
//
// 30 satisfied neither bound: this command now analyses every company of every
// tenant IN-PROCESS, so 30 minutes is a fleet-size assumption nobody made
// deliberately. 720 (12 h) is the chosen value for the daily per-tenant
// iterators: comfortably above any plausible fleet runtime at pilot scale, and
// half the 24-hour gap, so a crashed process is cleared long before the next
// run. Revisit it — do not just raise it — if a nightly run ever approaches
// hours.
Schedule::command('fraud:detect')
    ->dailyAt('02:00')
    ->withoutOverlapping(720)
    ->onFailure(function (): void {
        Log::error('fraud:detect exited non-zero — one or more tenants (or individual companies) failed their nightly fraud-pattern detection. For those companies no draft-abandonment analysis ran, so NO fraud alerts were raised and no fraud-triggered stock counting was scheduled. Per-company detail is in the application error log ("Fraud detection failed for company", tenant_id + company_id); per-tenant detail is under the TenantScopedCommand::forEachTenant failure entry.');
    });

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
// withoutOverlapping(30) sets the mutex EXPIRY to 30 minutes — two ticks —
// instead of the bare 1440-minute default, which would silently skip a full day
// of sweeps after one crashed run. It is NOT a runtime cap: a sweep that
// genuinely exceeds 30 minutes overlaps the tick after next rather than being
// killed. That bound is deliberate here — the sweep is a bounded UPDATE per
// tenant with no outbound I/O, so exceeding two ticks would itself be the
// incident. See the sizing note above `fraud:detect` (R4).
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

// Schedule: Poll platform for pending enrichment status updates.
//
// Two independent faults, both fixed 2026-08-05:
//   1. The command was never REGISTERED (it lives outside app/Console/Commands,
//      which is the only path Laravel auto-discovers), so this entry has been
//      shelling out to a non-existent artisan command. `Schedule::command()`
//      takes an unvalidated string, so `schedule:list` happily printed it.
//   2. Its pending-submission query is `Product::query()` — a TENANT table read
//      from the scheduler's CENTRAL connection since the 2026-05-28 flip. It is
//      now a TenantScopedCommand iterating via forEachTenant().
//
// withoutOverlapping(30) sets the mutex EXPIRY to two ticks instead of the bare
// 1440-minute default, exactly as inventory:expire-reservations does — one
// crashed run must not silence the poller for a day. That matters more here
// than elsewhere: this poller is the enrichment WEBHOOK's fallback path, so a
// silenced poller means enrichment results that missed the webhook are never
// picked up at all.
//
// KNOWN BOUND, recorded rather than papered over (R4, 2026-08-05 review). 30 is
// an expiry, NOT a runtime cap — this command makes up to 50 SYNCHRONOUS
// outbound HTTP calls per tenant, in-process (`checkStatusRaw`), so its wall
// clock is roughly N_tenants x 50 x RTT against a 30-minute expiry and a
// 15-minute cadence. At the pilot's single tenant that is minutes; the fleet
// size at which it stops holding is roughly 30min / (50 x RTT) tenants. Raising
// the expiry is the WRONG fix past that point — it would only trade overlap for
// a silenced poller. The right one is to fan the per-tenant poll out to the
// queue, which is tracked as follow-on work rather than done here (it changes
// the failure/alerting shape of the enrichment fallback path).
Schedule::command('enrichment:check-pending')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onFailure(function (): void {
        Log::error('enrichment:check-pending exited non-zero — one or more tenants failed to poll the platform for pending enrichment submissions. For those tenants, products stay stuck in Pending/Enriching: the enriched payload is never fetched, no enrichment_results row is created and nothing reaches the operator review queue. This command is also the enrichment webhook fallback, so a persistent failure means results that missed the webhook are lost until it recovers. Per-tenant detail is in the application error log under the TenantScopedCommand::forEachTenant failure entry (tenant_id + exception).');
    });

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

// Schedule: DPA Wave 3 T23 / D-26 — lane-separation detector, daily at 2:45 AM.
//
// Reports documents where the goods lane and the money lane disagree:
// invoiced-with-no-delivery (D-c), delivered-with-no-invoice (D-d), and goods
// lines that produced no stock movement at all (D-f). It repairs nothing.
//
// Run IN-PROCESS, deliberately. The neighbouring `check-subledger-reconciliation`
// entry above uses runInBackground() and is the ANTI-pattern, not the template:
// runInBackground() forks a detached process whose exit code the scheduler does
// not observe, so ->onFailure() would silently never fire and every finding
// would exist only in the log, unread.
Schedule::command(CheckCogsCoverageCommand::class)
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        Log::error('accounting:check-cogs-coverage exited non-zero — one or more companies have documents where the goods lane and the money lane disagree, or one or more tenants failed the scan. Findings are in the application log under [D-c] (posted goods invoice with no delivery behind it — after the pre-delivery invoicing policy took effect this should only ever contain LEGACY documents, each row carrying policy_at_post_time; a row stamped with a live policy means an unguarded posting path exists), [D-d] (confirmed delivery note with no invoice — feeds the 418 year-end accrual) and [D-f] (a goods line that produced no stock movement at all, so it will carry revenue and no COGS). Per-tenant failures are under the TenantScopedCommand::forEachTenant failure entry.');
    });
