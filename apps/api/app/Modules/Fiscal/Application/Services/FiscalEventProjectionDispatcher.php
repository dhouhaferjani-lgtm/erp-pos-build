<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Public projection dispatcher for **server-authored** fiscal events — Phase 5
 * customer-account deposit/payment design spec §2.4.
 *
 * `ACCOUNT_STATUS_CHANGED` — the only prior server-authored event — runs no
 * projectors. `DEPOSIT_RECEIPT` is the first server-authored event that must
 * drive the projection pipeline (printable receipt + treasury bridge).
 *
 * `OutboxIngestor::dispatchProjections()` already seeds projection rows + enqueues
 * jobs, but it is `private` and carries device-only suppression rules
 * (canonical-parse-failure §7.5, z-session lifecycle) that never apply to a
 * server-authored event. A server-authored event is always
 * `IntegrityStatus::Verified` / `PayloadParseStatus::Parsed` by construction (the
 * authoring service builds + validates the canonical payload before persisting).
 * This dispatcher ENFORCES that precondition at the boundary (rather than merely
 * assuming it) so a misuse — e.g. a quarantined device event passed in — fails
 * fast instead of bypassing the suppression the device path applies.
 *
 * Given a **persisted** server-authored `FiscalEvent`, this dispatcher:
 *   1. Asserts the event is server-only and Verified/Parsed.
 *   2. Resolves the active projector set via `FiscalEventProjectionRegistry`
 *      (same `(priority ASC, name ASC)` ordering, same `handlesEventType()` +
 *      `requiresModule()` activation gates as the device path).
 *   3. Inserts one pending `fiscal_event_projections` row per active projector
 *      with `ON CONFLICT (fiscal_event_id, projector_name) DO NOTHING`, so a
 *      replay/recovery call never raises a duplicate-key violation.
 *   4. Re-reads the still-`pending` rows for the event and enqueues one
 *      `ApplyFiscalEventProjectionJob` per row on `afterCommit`. This makes both
 *      "create missing rows" and "re-drive pending rows" replay-safe; the job
 *      short-circuits terminal (`applied`/`dead_lettered`) rows.
 *
 * The job runner, registry, priority order, and `(fiscal_event_id,
 * projector_name)` idempotency are all reused as-is. The shipped device ingest
 * path is left untouched.
 *
 * **Transaction shape.** Callers (e.g. `RecordCustomerDepositService`, Phase 5)
 * MUST wrap the authoring append + this dispatch in a single `DB::transaction()`
 * so the `fiscal_events` row and its projection rows commit atomically — otherwise
 * a crash between authoring and dispatch would leave a fiscal row with no
 * projection rows (no printable receipt / treasury bridge). `DB::afterCommit`
 * honors the OUTERMOST transaction's commit/rollback — the closure is `static`
 * (no `$this` capture) so a serialized queue payload never drags this service in.
 */
final class FiscalEventProjectionDispatcher
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FiscalEventProjectionRegistry $registry,
    ) {}

    /**
     * Seed projection rows for a persisted server-authored fiscal event and
     * enqueue their runner jobs after the surrounding transaction commits.
     *
     * @throws InvalidArgumentException when the event is not server-authored or
     *                                  is not Verified/Parsed (misuse of the
     *                                  server-authored projection boundary).
     */
    public function dispatch(FiscalEvent $event): void
    {
        $pendingRowIds = $this->seedProjections($event);

        if ($pendingRowIds === []) {
            return;
        }

        // After-commit hook: enqueue one ApplyFiscalEventProjectionJob per
        // pending row. The closure is `static` (no `$this` capture). Rows are
        // priority-sorted by the registry, so dispatch order mirrors lifecycle
        // dependency (POS-core projection @ 50, Treasury bridge @ 150).
        DB::afterCommit(static function () use ($pendingRowIds): void {
            foreach ($pendingRowIds as $rowId) {
                ApplyFiscalEventProjectionJob::dispatch($rowId);
            }
        });
    }

    /**
     * Seed (idempotently) one pending `fiscal_event_projections` row per active
     * projector for a persisted server-authored event, WITHOUT enqueuing any async
     * job. Returns every still-`pending` row id for the event.
     *
     * Split out from {@see dispatch()} so a request-time caller can seed the rows
     * inside the authoring transaction and then drive them synchronously
     * ({@see runSeededProjectionsSync()}) with no racing async worker — the async
     * path ({@see dispatch()}) layers the `afterCommit` enqueue on top.
     *
     * @return list<string> still-pending projection row ids for the event
     */
    public function seedProjections(FiscalEvent $event): array
    {
        $this->assertServerAuthoredVerified($event);

        $activeProjectors = $this->registry->activeProjectorsFor($event);

        $now = Carbon::now('UTC')->toDateTimeString();
        $pendingRows = [];
        foreach ($activeProjectors as $projector) {
            $pendingRows[] = [
                'id' => (string) Str::uuid(),
                'fiscal_event_id' => $event->id,
                'projector_name' => $projector->name(),
                'projection_status' => ProjectionStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Idempotent insert — a replay/recovery of the same event must not raise a
        // duplicate-key violation on the (fiscal_event_id, projector_name) UNIQUE.
        // No-op when there are no active projectors (the re-read below still
        // returns any prior rows to drive).
        $this->insertOnConflictDoNothing($pendingRows);

        // Re-read ALL still-`pending` rows for the event — deliberately NOT
        // filtered to the currently-active projector set. Projection rows are
        // activation-gated at CREATION; once a row exists, a module disabled before
        // its job runs must still have its row driven to completion (durable-row
        // semantics — see FiscalEventProjectionRegistry::byName() and
        // EnqueueResolvedEventProjectionsCommand::dispatchPendingRows()). Row ids
        // differ from the freshly-generated ones when a prior dispatch already
        // created them, so re-reading is what makes both "create missing rows" and
        // "re-drive pending rows" replay-safe.
        /** @var list<string> $pendingRowIds */
        $pendingRowIds = $this->db->table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->where('projection_status', ProjectionStatus::Pending->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $pendingRowIds;
    }

    /**
     * Drive every still-`pending` projection row for an event SYNCHRONOUSLY,
     * in-process, for a request-time caller that must return the projection outcome
     * (printable receipt + Treasury allocation) in the same response.
     *
     * Each row's `ApplyFiscalEventProjectionJob::handle()` is invoked DIRECTLY
     * (not through `Bus::dispatchSync()`), so the job's own
     * `pending → running → applied` lifecycle and attempt-accounting run, but the
     * sync-queue `failed()` hook does NOT — a projector that throws leaves its row
     * `pending` (retryable), never `dead_lettered`. Because nothing was enqueued
     * up-front there is no async worker to race with. Any row that still fails here
     * is handed to the async queue as a retry safety net (so Horizon eventually
     * completes it) and the failure is re-thrown so the caller can surface it.
     *
     * Call AFTER the authoring + {@see seedProjections()} transaction has committed
     * (so the projectors read committed state and run in their own transactions).
     *
     * @throws RuntimeException if any projection row failed (after queuing its retry)
     */
    public function runSeededProjectionsSync(FiscalEvent $event): void
    {
        /** @var list<string> $pendingRowIds */
        $pendingRowIds = $this->db->table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->where('projection_status', ProjectionStatus::Pending->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $failedRowIds = [];
        $lastError = null;
        foreach ($pendingRowIds as $rowId) {
            try {
                // Direct handle() — NOT Bus::dispatchSync(): the sync queue would
                // call the job's failed() hook on exception and mark the row
                // dead_lettered (terminal). Direct invocation runs the lifecycle +
                // advanceFailureAccounting (which resets the row to `pending`) and
                // lets the exception propagate without dead-lettering.
                (new ApplyFiscalEventProjectionJob($rowId))->handle($this->db, $this->registry);
            } catch (Throwable $e) {
                $failedRowIds[] = $rowId;
                $lastError = $e;
            }
        }

        if ($failedRowIds === []) {
            return;
        }

        // Hand the still-pending failed rows to the async queue so they retry and
        // eventually complete out of band, then surface the failure.
        foreach ($failedRowIds as $rowId) {
            ApplyFiscalEventProjectionJob::dispatch($rowId);
        }

        throw new RuntimeException(
            sprintf(
                'Synchronous projection run failed for fiscal_event_id=%s rows=[%s]; rows re-queued for async retry.',
                $event->id,
                implode(',', $failedRowIds),
            ),
            0,
            $lastError,
        );
    }

    /**
     * Enforce the server-authored precondition that lets this dispatcher safely
     * skip the device-path suppression rules.
     */
    private function assertServerAuthoredVerified(FiscalEvent $event): void
    {
        if (! $event->event_type->isServerOnly()) {
            throw new InvalidArgumentException(sprintf(
                'FiscalEventProjectionDispatcher only drives server-authored events; %s is not server-only.',
                $event->event_type->value,
            ));
        }

        if ($event->integrity_status !== IntegrityStatus::Verified
            || $event->payload_parse_status !== PayloadParseStatus::Parsed
        ) {
            throw new InvalidArgumentException(sprintf(
                'FiscalEventProjectionDispatcher requires a Verified/Parsed event; got '.
                'integrity_status=%s, payload_parse_status=%s for event %s.',
                $event->integrity_status->value,
                $event->payload_parse_status->value,
                $event->id,
            ));
        }
    }

    /**
     * Insert pending projection rows, ignoring rows that already exist on the
     * `(fiscal_event_id, projector_name)` UNIQUE — mirrors
     * `EnqueueResolvedEventProjectionsCommand::insertOnConflictDoNothing()`.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertOnConflictDoNothing(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columns));
        $valuePlaceholderRow = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $valuesSql = implode(', ', array_fill(0, count($rows), $valuePlaceholderRow));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col];
            }
        }

        if ($this->driverName() === 'pgsql') {
            $sql = sprintf(
                'INSERT INTO "fiscal_event_projections" (%s) VALUES %s '.
                'ON CONFLICT ON CONSTRAINT fiscal_event_projections_event_projector_unique DO NOTHING',
                $columnList,
                $valuesSql,
            );
        } else {
            $sql = sprintf(
                'INSERT INTO "fiscal_event_projections" (%s) VALUES %s '.
                'ON CONFLICT (fiscal_event_id, projector_name) DO NOTHING',
                $columnList,
                $valuesSql,
            );
        }

        $this->db->affectingStatement($sql, $bindings);
    }

    private function driverName(): string
    {
        if ($this->db instanceof Connection) {
            return $this->db->getDriverName();
        }

        return DB::connection()->getDriverName();
    }
}
