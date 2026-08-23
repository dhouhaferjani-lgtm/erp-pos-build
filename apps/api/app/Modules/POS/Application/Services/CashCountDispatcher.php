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
 * A durable `audit_events` row (`pos.cash_count_consumers_failed`) keyed on the
 * SAME `aggregate_id` (the shift id) that Treasury's own booked/skipped/
 * dead-lettered rows use, so one query by shift shows the whole story. It
 * carries every field a consumer would have needed, so the variance is
 * re-drivable by hand rather than lost. This is the same "never silent" contract
 * the Treasury listener follows — the lane exists because a missing GL leg went
 * unnoticed for months, and swallowing a push failure behind a `Log::` line
 * would re-create exactly that.
 *
 * Consumers that already ran before the throw are NOT re-run: the row records
 * the failing exception so an operator can tell how far the dispatch got.
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
     */
    private function recordUndeliverable(CashCountRecorded $event, Throwable $e): void
    {
        Log::error('Cash-count consumers could not be notified for a CLOSED shift; the Z report is sealed and will not be retried', [
            'shift_id' => $event->shiftId,
            'z_report_id' => $event->zReportId,
            'company_id' => $event->companyId,
            'aggregate_variance' => $event->aggregateVariance->amount,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

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
            Log::critical('Cash-count consumers failed AND the failure could not be recorded durably', [
                'shift_id' => $event->shiftId,
                'z_report_id' => $event->zReportId,
                'original_exception' => $e::class,
                'original_message' => $e->getMessage(),
                'audit_exception' => $auditFailure::class,
            ]);
        }
    }
}
