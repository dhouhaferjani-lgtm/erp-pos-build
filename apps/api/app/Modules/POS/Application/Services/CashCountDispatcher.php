<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Compliance\Services\AuditService;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single guarded chokepoint for raising {@see CashCountRecorded} — gate
 * round 1, finding P2-1.
 *
 * ── The invariant ───────────────────────────────────────────────────────────
 * By the time this event is raised the Z report is COMMITTED and HASH-CHAINED
 * and the shift is CLOSED. Every consumer of the event is a post-close side
 * effect: a fraud alert, and (since R-8) a queued GL leg. **None of them can
 * un-seal the Z report, so none of them may fail the close.** A 500 here tells
 * an offline-first POS that a close failed when it actually succeeded — and the
 * device cannot re-raise it, because a re-sync short-circuits at `200
 * duplicate` before the dispatch block.
 *
 * ── Why this exists now ─────────────────────────────────────────────────────
 * `Dispatcher::dispatch()` has no try/catch, `DatabaseTransactionsManager::commit()`
 * executes `afterCommit` callbacks bare, and `ReportController::generateZReport()`
 * catches only four POS domain types — so anything a consumer throws becomes an
 * HTTP 500 on a sealed fiscal document. Two distinct throw sources reach it:
 *
 *   1. **The queue push** (new with R-8). `PostShiftCashVarianceAdjustment` is
 *      `ShouldQueue`, so the dispatcher calls `$connection->pushOn()` inline;
 *      a Redis outage, a driver fault or a serialization failure propagates out
 *      of `event()`. The listener's own never-block guarantee covers everything
 *      AFTER the job starts and nothing before it.
 *   2. **The synchronous fraud listener** (pre-existing).
 *      `OpenFraudAlertForShiftVariance::handle()` writes `fraud_alerts` and may
 *      dispatch email; neither is wrapped. A DB or mail fault there has always
 *      been able to 500 a sealed close. That hole is closed here too, which is a
 *      DELIBERATE and disclosed behaviour change beyond R-8's own defect: the
 *      invariant is a property of this seam, not of one listener.
 *
 * ── What a failure degrades to ──────────────────────────────────────────────
 * `report($e)` (so the fault keeps the alerting reach it had as an unhandled
 * 500 — see {@see recordUndeliverable()}) plus a durable `audit_events` row
 * (`pos.cash_count_consumers_failed`) keyed on the SAME `aggregate_id` (the
 * shift id) that Treasury's own booked/skipped/dead-lettered rows use, so one
 * query by shift shows the whole story. It carries every field a consumer would
 * have needed, so the variance is re-drivable by hand rather than lost. This is
 * the same "never silent" contract the Treasury listener follows — the lane
 * exists because a missing GL leg went unnoticed for months, and swallowing a
 * push failure behind a `Log::` line would re-create exactly that.
 *
 * ── ALL-OR-NOTHING, and the ordering is load-bearing (gate round 2, F-3) ─────
 * Be precise about what this guard does and does not buy. It is a guard around
 * the WHOLE dispatch, not per consumer. `Dispatcher::invokeListeners()` has no
 * per-listener `try/catch`, so **the first consumer to throw aborts every
 * consumer after it**, and this class then records one row for the lot.
 *
 * The order is fixed and matters:
 *   1. the QUEUE PUSH for Treasury's `PostShiftCashVarianceAdjustment`
 *      (`bootstrap/providers.php` registers `TreasuryServiceProvider` BEFORE
 *      `ComplianceServiceProvider`),
 *   2. the FRAUD ALERT, `OpenFraudAlertForShiftVariance` — the `fraud_alerts`
 *      row and its email,
 *   3. the STORED-EVENT write, Spatie's wildcard `*` subscriber, which the
 *      dispatcher always runs after the concrete listeners.
 *
 * So the two faults are NOT symmetrical, and an operator reading the audit row
 * must know which one they have (the row carries the exception class):
 *   - **a push failure** (Redis down) throws in (1) and therefore suppresses the
 *     fraud alert, the fraud email AND the stored-event write. The audit row is
 *     the only survivor. Nothing else ran.
 *   - **a fraud-listener failure** throws in (2); the GL job is ALREADY enqueued
 *     and will run, and only the stored-event write is lost.
 * Consumers that already ran before the throw are never re-run.
 *
 * Per-listener isolation would remove the asymmetry, and is deliberately NOT
 * done here: `treasury.shift_variance_gl_enabled` is false, so `shouldQueue()`
 * short-circuits before the push and case (1) cannot occur yet. The residual is
 * a pre-enable item on the G-5 checklist
 * (`docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md`).
 * The ordering itself is pinned by `CashCountDispatchGuardTest` so a provider
 * reshuffle is a visible test failure rather than a silent inversion of which
 * consumers survive which fault.
 */
final readonly class CashCountDispatcher
{
    /**
     * One event type, queryable alongside the Treasury rows.
     */
    private const UNDELIVERABLE_EVENT = 'pos.cash_count_consumers_failed';

    public function __construct(
        private AuditService $auditService,
    ) {}

    public function dispatch(CashCountRecorded $event): void
    {
        try {
            Event::dispatch($event);
        } catch (Throwable $e) {
            $this->recordUndeliverable($event, $e);
        }
    }

    /**
     * Never throws — this IS the never-block guarantee, so a failure to record
     * the failure must not resurface on the close.
     *
     * Gate round 2, findings F-1 and F-4. Three INDEPENDENT legs, each in its own
     * guard, in this order:
     *
     *   1. `report($e)` — FIRST, deliberately. Before this class existed, a
     *      throwing consumer became an unhandled exception, reached Laravel's
     *      handler and therefore Sentry, and paged whoever is wired to it.
     *      Swallowing it is only defensible if it still reaches an error
     *      reporter: `LOG_STACK=single` puts no `sentry` channel in the log
     *      stack and `config/sentry.php` `enable_logs` defaults to false, so a
     *      `Log::error` line alone is NOT alerting reach. It runs before the
     *      audit write so a failing audit write cannot suppress it.
     *   2. the log line, for local/forensic reading.
     *   3. the durable `audit_events` row.
     *
     * Each leg is guarded separately rather than the whole body once, so no leg
     * can suppress a later one — a broken logger must not cost us the audit row,
     * and a broken audit store must not cost us the Sentry event. F-4: the
     * `Log::` calls used to sit OUTSIDE any try, and this method runs inside a
     * `catch`, so an unwritable `storage/logs` (Monolog's StreamHandler throws
     * `UnexpectedValueException`) would have propagated onto the sealed-close
     * stack — the one failure mode this class exists to make impossible.
     */
    private function recordUndeliverable(CashCountRecorded $event, Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // The error reporter itself is down. There is nothing to escalate
            // to, and escalating would mean failing a sealed close. Fall
            // through to the log line and the audit row.
        }

        try {
            Log::error('Cash-count consumers could not be notified for a CLOSED shift; the Z report is sealed and will not be retried', [
                'shift_id' => $event->shiftId,
                'z_report_id' => $event->zReportId,
                'company_id' => $event->companyId,
                'aggregate_variance' => $event->aggregateVariance->amount,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // An unwritable log destination must not cost us the audit row.
        }

        try {
            $this->auditService->record(
                companyId: $event->companyId,
                userId: $event->cashierId,
                eventType: self::UNDELIVERABLE_EVENT,
                aggregateType: 'pos_shift',
                aggregateId: $event->shiftId,
                payload: [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'tenant_id' => $event->tenantId,
                    'company_id' => $event->companyId,
                    'shift_id' => $event->shiftId,
                    'z_report_id' => $event->zReportId,
                    'terminal_id' => $event->terminalId,
                    'cashier_id' => $event->cashierId,
                    'currency' => $event->currencyCode,
                    'aggregate_variance' => $event->aggregateVariance->amount,
                    'variance_direction' => $event->varianceDirection->value,
                    'severity' => $event->severity->value,
                    'tender_breakdown' => array_map(
                        fn (CashCountBreakdownDTO $b): array => [
                            'payment_method_id' => $b->paymentMethodId,
                            'currency_code' => $b->currencyCode,
                            'expected_amount' => $b->expectedAmount,
                            'actual_amount' => $b->actualAmount,
                            'variance_amount' => $b->varianceAmount,
                        ],
                        $event->tenderBreakdown,
                    ),
                    'recorded_at' => $event->recordedAt,
                ],
                metadata: [
                    'z_report_id' => $event->zReportId,
                    'terminal_id' => $event->terminalId,
                    'severity' => 'error',
                ],
            );
        } catch (Throwable $auditFailure) {
            try {
                Log::critical('Cash-count consumers failed AND the failure could not be recorded durably', [
                    'shift_id' => $event->shiftId,
                    'z_report_id' => $event->zReportId,
                    'original_exception' => $e::class,
                    'original_message' => $e->getMessage(),
                    'audit_exception' => $auditFailure::class,
                ]);
            } catch (Throwable) {
                // Everything downstream of the close has now failed. The close
                // itself still stands, which is the only thing this class
                // guarantees; `report($e)` above already left the trail.
            }
        }
    }
}
