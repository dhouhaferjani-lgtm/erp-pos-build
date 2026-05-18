<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Horizon-owned runner for a single `fiscal_event_projections` row —
 * spec v7 §7.5 + plan §1705.
 *
 * **Where lifecycle state lives.**
 *   - Horizon owns transient state — retry counts, backoff, delivery
 *     guarantees, lock primitives at the queue layer.
 *   - The `fiscal_event_projections` table holds durable, operator-visible
 *     state — `projection_status`, `attempts`, `last_error`,
 *     `last_attempted_at`, `applied_at`, `dead_lettered_at`. Operators
 *     query this table to find stuck or dead-lettered projections.
 *
 * **Lifecycle state machine.**
 *
 *     pending ──handle()──► running ─┬─success─► applied        (terminal)
 *                                    │
 *                                    └─throw──► running         (retry)
 *                                          │
 *                                          └─exhausted──► dead_lettered (terminal)
 *
 * `running` is NOT terminal — between Horizon retries the row stays
 * `running` so operator tooling sees it as in-flight; the
 * `last_attempted_at` and `attempts` columns evolve. `failed()` is invoked
 * by Horizon ONLY after `$tries` is exhausted; that's the only path to
 * `dead_lettered`.
 *
 * **Two-transaction shape (spec §7.5 / plan §1796 amendment).**
 * `handle()` uses TWO transactions:
 *   - **T_lock** — opens, loads the row with `lockForUpdate()`,
 *     short-circuits on terminal states, otherwise flips `pending|running →
 *     running`, commits. Lifecycle lock is row-scoped so sibling workers
 *     on DIFFERENT projection rows aren't blocked.
 *   - **T_apply** — the projector's OWN transaction boundary (e.g.,
 *     `TreasuryReceiptBridge` wraps Payment + GL + allocation in a single
 *     `DB::transaction()` per Task 22). Runs OUTSIDE T_lock so the lock
 *     duration is bounded by the cheap row update, not by the projector's
 *     downstream writes.
 *
 * **Why terminal-status writes live OUTSIDE T_apply.**
 *   - Success path: `applied` + `applied_at` are written by `handle()`
 *     AFTER the projector returns cleanly. If T_apply had owned the write,
 *     a projector that called `DB::rollBack()` on a soft-error path would
 *     have its `applied` flag rolled back too, leaving the row in an
 *     inconsistent state.
 *   - Failure path: `attempts++`, `last_error`, `last_attempted_at` are
 *     written by `handle()` AFTER catching the projector's throw —
 *     OUTSIDE T_apply because T_apply rolled back, taking attempt
 *     accounting with it if we'd written inside.
 *
 * **Lifecycle short-circuit (Task 22 cross-task implication).** A
 * re-delivery of an already-`applied` or already-`dead_lettered` row
 * commits T_lock + returns, never invoking the projector. This is the
 * second of two defense layers atop Task 22's projector-level
 * `pg_advisory_xact_lock`; neither alone is sufficient.
 *
 * **Fail-closed on registry / model lookup misconfiguration.**
 * If the projection row vanishes (impossible under normal operation, but
 * defensible), or the `FiscalEvent` row vanishes (also impossible — Task 8
 * triggers block deletes), or the registry has no projector matching
 * `projector_name` (misconfiguration — projector removed between ingest
 * and run), the job logs `Log::critical` and throws so Horizon's
 * `failed()` handler flips the row to `dead_lettered` after retry
 * exhaustion. We never silently no-op a misconfiguration.
 *
 * **Activation gating happens at ingest, not at run.** The row exists
 * BECAUSE activation gating passed at ingest time (the registry's
 * `activeProjectorsFor()` ran inside `OutboxIngestor::dispatchProjections()`
 * and seeded the row). At job-run time we resolve the projector by `name()`
 * via `FiscalEventProjectionRegistry::byName()` — which ignores current
 * activation state. A module deactivated between ingest and run would
 * otherwise orphan the row indefinitely.
 *
 * **System-scoped job.** No `BindsTenantContext` trait — fiscal events are
 * global chain truth. The projector itself looks up tenant/company on the
 * `FiscalEvent` if it needs to rebind context.
 *
 * **Tries / backoff.** `$tries = 5` with exponential backoff
 * `[10, 30, 60, 300, 900]` seconds. The five-try ceiling is comfortable
 * for transient downstream outages (GL post failure, payment-method lookup
 * cache miss) without churning Horizon's queue for hours on a genuinely
 * stuck projection.
 */
final class ApplyFiscalEventProjectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Horizon retries up to this many times before invoking `failed()`.
     * Five tries with the exponential backoff below gives ~21 minutes of
     * total runway — enough for transient cache outages, GL post lock
     * contention, etc., without burning Horizon throughput on terminal
     * failures.
     */
    public int $tries = 5;

    /**
     * Maximum seconds a single attempt may run before Horizon kills it.
     * The projectors do small bounded writes — POS-core inserts a handful
     * of rows; Treasury bridge inserts a Payment + GL entries + an
     * allocation. 120s is generous; anything longer is a hung downstream
     * and Horizon's timeout should fire to surface the stall.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly string $projectionRowId,
    ) {
        $this->onQueue('fiscal-projections');
    }

    /**
     * @return list<int> exponential backoff between retries (seconds)
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    /**
     * Execute the projection.
     *
     * Step 1 (T_lock): load the row with `lockForUpdate()`, short-circuit
     * on terminal states, otherwise flip to `running` and commit.
     * Step 2 (T_apply): resolve the projector + the FiscalEvent and call
     * `projector.apply($event)`. The projector owns its own transaction.
     * Step 3: on success → write terminal `applied` + `applied_at`.
     *         On throw → advance `attempts` / `last_error` /
     *         `last_attempted_at` and re-throw for Horizon retry.
     */
    public function handle(
        ConnectionInterface $db,
        FiscalEventProjectionRegistry $registry,
    ): void {
        // ---- Step 1: T_lock — short transaction, holds the row-level
        // lifecycle lock only long enough to short-circuit terminal
        // states or flip to `running`. Releases before the projector
        // runs so sibling workers on different rows aren't blocked.
        $proceed = $db->transaction(function (): bool {
            $row = FiscalEventProjectionRow::query()
                ->lockForUpdate()
                ->find($this->projectionRowId);

            if ($row === null) {
                // The row vanished between dispatch and handle — physically
                // impossible under §7.5 (Task 8's BEFORE DELETE trigger on
                // fiscal_events forbids upstream deletes; the projection
                // row carries no separate delete path) but defensible.
                Log::critical(
                    'ApplyFiscalEventProjectionJob: fiscal_event_projections row not found inside T_lock.',
                    ['projection_row_id' => $this->projectionRowId],
                );
                throw new RuntimeException(sprintf(
                    'ApplyFiscalEventProjectionJob: fiscal_event_projections row %s not found.',
                    $this->projectionRowId,
                ));
            }

            // Idempotent short-circuit — re-delivery of a terminal-state row
            // is a no-op. Covers Horizon double-dispatch, crash recovery on
            // a finished row, accidental manual dispatch after dead-letter.
            if ($row->projection_status === ProjectionStatus::Applied
                || $row->projection_status === ProjectionStatus::DeadLettered
            ) {
                // Returning `false` tells the outer flow to skip T_apply.
                // The lock is released by the surrounding commit.
                return false;
            }

            // Either `pending` first run or `running` crash recovery from a
            // worker that died mid-apply. Both flip to `running` here; the
            // attempts column already reflects prior failures (advanced
            // outside T_apply on the earlier throw path).
            $row->projection_status = ProjectionStatus::Running;
            $row->save();

            return true;
        });

        if (! $proceed) {
            return; // terminal-state short-circuit; the lock-and-commit was the work.
        }

        // ---- Step 2: T_apply — owned by the projector. The job
        // resolves dependencies OUTSIDE T_lock + outside T_apply.

        // Re-load WITHOUT the lock — we already flipped the row and
        // committed; T_apply is the projector's own boundary, so this
        // load is just for the FiscalEvent FK on this row.
        $row = FiscalEventProjectionRow::query()->find($this->projectionRowId);

        if ($row === null) {
            // Same defense as above; impossible in practice.
            Log::critical(
                'ApplyFiscalEventProjectionJob: fiscal_event_projections row vanished between T_lock commit and projector resolution.',
                ['projection_row_id' => $this->projectionRowId],
            );
            throw new RuntimeException(sprintf(
                'ApplyFiscalEventProjectionJob: fiscal_event_projections row %s vanished post-T_lock.',
                $this->projectionRowId,
            ));
        }

        // Resolve the FiscalEvent. Wrap find() in try/catch(QueryException)
        // per the Task 17 standing pattern — PG's `uuid`-typed PK rejects
        // a malformed UUID string at the driver layer with a QueryException
        // rather than returning null. We treat both null and exception as
        // hard misconfiguration: log critical, dead-letter the projection
        // row via the throw path, and propagate so Horizon's failed() flips
        // it after retry exhaustion.
        $event = $this->loadFiscalEvent($row->fiscal_event_id);

        if ($event === null) {
            $this->recordHardFailure(
                $row,
                'FiscalEvent row not found for fiscal_event_id='.$row->fiscal_event_id,
                projectorName: $row->projector_name,
            );
            throw new RuntimeException(sprintf(
                'ApplyFiscalEventProjectionJob: FiscalEvent %s not found (referenced by projection row %s).',
                $row->fiscal_event_id,
                $this->projectionRowId,
            ));
        }

        $projector = $registry->byName($row->projector_name);

        if ($projector === null) {
            // Constructor-asserted invariant violation in the registry's
            // tagged set vs. the persisted row — projector deregistered
            // (e.g., module uninstall) between ingest and run. The plan
            // §1705 calls this a hard misconfiguration, not a retryable
            // condition; log critical + propagate so the row dead-letters
            // after retry exhaustion. Operator restores the projector
            // registration or resolves through a dedicated command.
            $this->recordHardFailure(
                $row,
                sprintf(
                    'No FiscalEventProjector registered with name "%s" — possible projector deregistered between ingest and run.',
                    $row->projector_name,
                ),
                projectorName: $row->projector_name,
            );
            throw new RuntimeException(sprintf(
                'ApplyFiscalEventProjectionJob: no projector named "%s" registered.',
                $row->projector_name,
            ));
        }

        // ---- Step 3: invoke projector (T_apply owned by projector) +
        // terminal-status write OUTSIDE T_apply.
        try {
            $projector->apply($event);
        } catch (Throwable $e) {
            // Projector threw. Advance attempt accounting OUTSIDE T_apply
            // (T_apply rolled back; if attempt accounting had been written
            // inside, the rollback would lose it). Re-throw so Horizon
            // retries with backoff; only after exhausting `$tries` does
            // `failed()` flip the row to `dead_lettered`.
            $this->advanceFailureAccounting($row, $e);
            throw $e;
        }

        // Success — terminal write OUTSIDE T_apply. If T_apply soft-rolled
        // back internally (e.g., projector's idempotency short-circuit
        // returned cleanly without writing), the row still flips to
        // `applied` because the projector reported success.
        $row->projection_status = ProjectionStatus::Applied;
        $row->applied_at = Carbon::now('UTC');
        $row->save();
    }

    /**
     * Horizon invokes this AFTER `$tries` is exhausted. Flips the row to
     * `dead_lettered` so operator tooling can see it in the dead-letter
     * view; resolution moves through a separate command (never a re-handle()).
     *
     * The standing pattern: log critical so the dead-letter is observable
     * in production alerting (Sentry / structured-log monitor / pager).
     *
     * Idempotent — re-invocation flips an already-`dead_lettered` row
     * back to `dead_lettered` (no-op state transition); `dead_lettered_at`
     * stays at the first failure time. This mirrors the `handle()`
     * short-circuit on terminal states: a row can pass through `failed()`
     * once and stay in its dead-letter terminal state for operator
     * resolution.
     */
    public function failed(Throwable $exception): void
    {
        $row = FiscalEventProjectionRow::query()->find($this->projectionRowId);

        if ($row === null) {
            // Defensive — the row vanished before Horizon called failed().
            // Nothing actionable; log so the operator can investigate.
            Log::critical(
                'ApplyFiscalEventProjectionJob::failed(): fiscal_event_projections row not found.',
                [
                    'projection_row_id' => $this->projectionRowId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            return;
        }

        // If a prior failed() call already flipped this row, leave the
        // dead_lettered_at timestamp at the first failure time.
        if ($row->projection_status !== ProjectionStatus::DeadLettered) {
            $row->projection_status = ProjectionStatus::DeadLettered;
            $row->dead_lettered_at = Carbon::now('UTC');
            // Capture the terminal exception as last_error so the operator
            // doesn't need to cross-reference Horizon failed-jobs to see why.
            $row->last_error = $this->truncateError(
                $exception::class.': '.$exception->getMessage(),
            );
            $row->save();
        }

        Log::critical(
            'ApplyFiscalEventProjectionJob: projection dead-lettered after retry exhaustion.',
            [
                'projection_row_id' => $this->projectionRowId,
                'fiscal_event_id' => $row->fiscal_event_id,
                'projector_name' => $row->projector_name,
                'attempts' => $row->attempts,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }

    /**
     * Resolve a FiscalEvent by id; null on missing, null on driver-level
     * QueryException (a malformed-UUID hard error on PG's `uuid`-typed PK).
     * The caller treats both null returns identically as hard
     * misconfiguration.
     */
    private function loadFiscalEvent(string $fiscalEventId): ?FiscalEvent
    {
        try {
            return FiscalEvent::query()->find($fiscalEventId);
        } catch (QueryException $e) {
            Log::critical(
                'ApplyFiscalEventProjectionJob: QueryException loading FiscalEvent — treating as missing.',
                [
                    'fiscal_event_id' => $fiscalEventId,
                    'projection_row_id' => $this->projectionRowId,
                    'sqlstate' => $e->getCode(),
                    'message' => $e->getMessage(),
                ],
            );

            return null;
        }
    }

    /**
     * Advance attempt accounting OUTSIDE T_apply when the projector throws.
     * `attempts++`, capture `last_error` + `last_attempted_at`. Status
     * stays `running` between Horizon retries — operator tooling sees the
     * row as in-flight, not stuck.
     */
    private function advanceFailureAccounting(FiscalEventProjectionRow $row, Throwable $e): void
    {
        $row->attempts = $row->attempts + 1;
        $row->last_error = $this->truncateError($e::class.': '.$e->getMessage());
        $row->last_attempted_at = Carbon::now('UTC');
        $row->save();
    }

    /**
     * Record a hard misconfiguration failure (missing FiscalEvent, missing
     * projector). Advances `attempts` and `last_attempted_at` so the row
     * shows the failure even before Horizon's retry exhaustion fires
     * `failed()`.
     */
    private function recordHardFailure(
        FiscalEventProjectionRow $row,
        string $reason,
        string $projectorName,
    ): void {
        Log::critical(
            'ApplyFiscalEventProjectionJob: hard misconfiguration failure.',
            [
                'projection_row_id' => $this->projectionRowId,
                'projector_name' => $projectorName,
                'fiscal_event_id' => $row->fiscal_event_id,
                'reason' => $reason,
            ],
        );

        $row->attempts = $row->attempts + 1;
        $row->last_error = $this->truncateError($reason);
        $row->last_attempted_at = Carbon::now('UTC');
        $row->save();
    }

    /**
     * `fiscal_event_projections.last_error` is `TEXT` (unbounded on PG)
     * but extremely long messages are operator-hostile. Cap at 2 KiB —
     * enough for stack frames + the underlying SQL message + projector
     * context, without bloating the row.
     */
    private function truncateError(string $message): string
    {
        $limit = 2048;
        if (strlen($message) <= $limit) {
            return $message;
        }

        return substr($message, 0, $limit - 14).'…[truncated]';
    }
}
