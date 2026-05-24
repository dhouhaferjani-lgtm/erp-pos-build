<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * `fiscal:enqueue-resolved-event-projections` — spec v7 §15.2 + Task 24.
 *
 * Named recovery path for the "the resolution transaction committed
 * (`payload_parse_status = parsed`) but the after-commit enqueue never
 * ran" crash window. Also runnable by an operator any time the projection
 * rows for a resolved event are missing or stuck in `pending` without a
 * live queue job behind them.
 *
 * Per-row contract:
 *   - (a) Create any MISSING `pending` `fiscal_event_projections` rows for
 *     the currently-active projectors against the row's event-type +
 *     tenant + company (via `FiscalEventProjectionRegistry::activeProjectorsFor`,
 *     same call site as `OutboxIngestor::dispatchProjections`). Uses
 *     `INSERT … ON CONFLICT DO NOTHING` on the
 *     `fiscal_event_projections_event_projector_unique` constraint so a
 *     concurrent dispatch / re-run never duplicates a row.
 *   - (b) For every `pending` row attached to the event (newly-created
 *     OR pre-existing), dispatch one `ApplyFiscalEventProjectionJob`.
 *     `running` / `applied` / `dead_lettered` rows are NEVER re-enqueued
 *     — they are owned by Horizon's lifecycle (Task 23).
 *
 * **Idempotency.** Safe to re-run any number of times: the UNIQUE
 * constraint prevents row duplication; the `pending` filter prevents
 * lifecycle interference; Task 23's `WithoutOverlapping` middleware on
 * the projection job prevents duplicate concurrent execution if the same
 * row gets enqueued twice within the lock window.
 *
 * **Permission gate.** Spec §15.2 requires
 * `fiscal.events.resolve_quarantine`. Commands run system-scoped without
 * an authenticated user, so the command requires an `--actor-id={uuid}`
 * flag and checks the permission against that user. Without the flag, or
 * with an unknown user id, or with a user that lacks the permission, the
 * command exits with code 1.
 *
 * **Spatie team context (round-2 Task 24 Codex T24-P1).** Spatie
 * permissions are tenant-team-scoped (`config/permission.php:134`),
 * which means `$user->can(...)` consults the team id currently set on
 * the `PermissionRegistrar` singleton — NOT the actor's `tenant_id`.
 * Console commands enter without any team context (Laravel sets none),
 * so the round-1 implementation relied on whatever team id happened to
 * be set by an earlier-in-process request or test setUp. We re-scope
 * the registrar to the actor's tenant in a try/finally before invoking
 * `can()`, then restore the previous team id — mirroring the
 * `SetPermissionsTeam` middleware pattern.
 *
 * **`--actor-id` design note (round-1 Opus F1 deferred per round-2
 * disposition).** Spec §15.2 names the gate but does not specify the
 * mechanism. The `--actor-id` flag is a Phase 1 implementation choice
 * — pragmatic for a Laravel console command without an authenticated
 * request user. A formal spec amendment to §15.2 documenting the
 * convention is deferred to a follow-up documentation task. Task 31's
 * `fiscal:verify-event-chain` command should mirror this convention.
 *
 * **Fail-closed on resolver throws.** If
 * `FiscalEventProjectionRegistry::activeProjectorsFor()` throws for one
 * row (resolver outage, registry constructor invariant violation, etc.),
 * the command logs critical, skips that row, and continues — never
 * crashes mid-batch. This mirrors the Task 18 F1 standing pattern.
 *
 * **Exit codes (per Task 24 brief):**
 *   - 0 — success (including no-op when nothing to enqueue)
 *   - 1 — permission denied OR validation error (unknown user, missing
 *         actor, unknown fiscal-event-id when targeted explicitly)
 *   - 2 — transient failure (registry-resolver hard error, DB connection
 *         lost mid-loop, etc.); operator re-runs to retry
 *
 * @cross-tenant-by-design Operator recovery command may iterate fiscal events across tenants, with explicit tenant filtering and per-actor permission checks.
 */
final class EnqueueResolvedEventProjectionsCommand extends Command
{
    /** @var string */
    protected $signature = 'fiscal:enqueue-resolved-event-projections '.
        '{--fiscal-event-id= : restrict to a single fiscal_events.id} '.
        '{--tenant= : restrict to one tenant_id} '.
        '{--actor-id= : authenticated user id performing the action (required for the permission gate)}';

    /** @var string */
    protected $description = 'Recover pending fiscal_event_projections rows for parse-resolved events (spec §15.2).';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FiscalEventProjectionRegistry $projectionRegistry,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // ---- Permission gate (Task 24 brief pattern (a)) ----
        $actorId = $this->option('actor-id');
        if (! is_string($actorId) || $actorId === '') {
            $this->error('Missing --actor-id flag; required for fiscal.events.resolve_quarantine gate.');

            return self::FAILURE;
        }

        $actor = User::query()->find($actorId);
        if ($actor === null) {
            $this->error(sprintf('Unknown actor user id %s.', $actorId));

            return self::FAILURE;
        }

        // Round-2 (Task 24 Codex T24-P1) — re-scope the Spatie permission
        // team to the actor's tenant before checking `can()`. Spatie
        // permissions are team-scoped (config/permission.php:134); without
        // this, `$actor->can(...)` evaluates against whatever team id was
        // previously set on the registrar (NULL in a clean console
        // context, leaking through any test setUp value, etc.). Mirrors
        // the `SetPermissionsTeam` HTTP middleware pattern.
        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        try {
            $this->permissionRegistrar->setPermissionsTeamId($actor->tenant_id);

            if (! $actor->can('fiscal.events.resolve_quarantine')) {
                $this->error(sprintf(
                    'Actor %s lacks the fiscal.events.resolve_quarantine permission.',
                    $actorId,
                ));

                return self::FAILURE;
            }
        } finally {
            // Always restore — even if `can()` threw or we returned
            // early. Mirrors the fail-closed try/finally discipline from
            // Task 18 F1 and Task 23 R3-F2 (Carbon::setTestNow).
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
        }

        // ---- Resolve the target rows ----
        $query = FiscalEvent::query()
            ->where('payload_parse_status', PayloadParseStatus::Parsed->value);

        $fiscalEventId = $this->option('fiscal-event-id');
        if (is_string($fiscalEventId) && $fiscalEventId !== '') {
            $query->where('id', $fiscalEventId);
        }

        $tenantOption = $this->option('tenant');
        if (is_string($tenantOption) && $tenantOption !== '') {
            $query->where('tenant_id', $tenantOption);
        }

        try {
            $events = $query->get();
        } catch (Throwable $e) {
            // Driver-level failure on the query itself (e.g. malformed UUID
            // option against PG's `uuid` PK type). Surface as transient
            // failure so a re-run with a corrected option succeeds.
            Log::error(
                'EnqueueResolvedEventProjectionsCommand: query failure on candidate selection.',
                [
                    'actor_id' => $actorId,
                    'fiscal_event_id_option' => $fiscalEventId,
                    'tenant_option' => $tenantOption,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
            $this->error(sprintf('Query failure: %s', $e->getMessage()));

            return 2;
        }

        if ($events->isEmpty()) {
            $this->info('No fiscal_events rows match the selection; nothing to do.');

            return self::SUCCESS;
        }

        $createdRowCount = 0;
        $dispatchedCount = 0;
        $hardFailureCount = 0;

        foreach ($events as $event) {
            try {
                $created = $this->createMissingPendingRows($event);
                $createdRowCount += $created;

                $dispatched = $this->dispatchPendingRows($event);
                $dispatchedCount += $dispatched;
            } catch (Throwable $e) {
                // Fail-closed per Task 18 F1 — never crash mid-batch on a
                // resolver throw or a transient row failure. Log + skip +
                // count for the operator summary.
                $hardFailureCount++;
                Log::error(
                    'EnqueueResolvedEventProjectionsCommand: per-row failure; skipping row and continuing batch.',
                    [
                        'fiscal_event_id' => $event->id,
                        'tenant_id' => $event->tenant_id,
                        'event_type' => $event->event_type->value,
                        'actor_id' => $actorId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                );
            }
        }

        $this->info(sprintf(
            'Processed %d resolved fiscal_events; created %d missing projection rows; dispatched %d pending rows; %d per-row failures.',
            $events->count(),
            $createdRowCount,
            $dispatchedCount,
            $hardFailureCount,
        ));

        // A per-row resolver failure is a transient signal — the row stays
        // in its current state and a re-run can succeed once the
        // resolver / registry is healthy again. Surface as exit code 2 so
        // an automation layer can distinguish "did nothing" from
        // "did partial work".
        return $hardFailureCount > 0 ? 2 : self::SUCCESS;
    }

    /**
     * Insert any MISSING `pending` `fiscal_event_projections` rows for
     * the currently-active projectors. The UNIQUE
     * `(fiscal_event_id, projector_name)` constraint backs idempotency —
     * we use `INSERT … ON CONFLICT DO NOTHING` so a concurrent run /
     * post-resolve dispatch never raises a duplicate-key error.
     *
     * @return int the number of NEW rows created (may be 0 in the steady-
     *             state common path where the resolution transaction
     *             already created them)
     */
    private function createMissingPendingRows(FiscalEvent $event): int
    {
        // Resolver throws (DB outage, cache backend down) surface here —
        // the registry catches them internally per F1 round-2, but we
        // still wrap as a belt-and-braces.
        $activeProjectors = $this->projectionRegistry->activeProjectorsFor($event);

        if ($activeProjectors === []) {
            return 0;
        }

        $now = Carbon::now('UTC')->toDateTimeString();
        $rows = [];
        foreach ($activeProjectors as $projector) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'fiscal_event_id' => $event->id,
                'projector_name' => $projector->name(),
                'projection_status' => ProjectionStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $this->insertOnConflictDoNothing($rows);
    }

    /**
     * Driver-portable `INSERT … ON CONFLICT DO NOTHING` against the
     * `fiscal_event_projections_event_projector_unique` constraint.
     * Returns the count of rows actually inserted (0 when every row was
     * suppressed by the constraint).
     *
     * Mirrors `OutboxIngestor::insertOnConflictDoNothingReturningId`'s
     * driver-targeting pattern (Task 19 standing pattern — DB primitives
     * spec-named are load-bearing): targeting the constraint NAME on PG
     * ensures only the (fiscal_event_id, projector_name) collision is
     * silenced, not any other UNIQUE / NOT NULL / CHECK violation.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertOnConflictDoNothing(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $driver = $this->driverName();
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

        if ($driver === 'pgsql') {
            $sql = sprintf(
                'INSERT INTO "fiscal_event_projections" (%s) VALUES %s '.
                'ON CONFLICT ON CONSTRAINT fiscal_event_projections_event_projector_unique '.
                'DO NOTHING',
                $columnList,
                $valuesSql,
            );
        } else {
            // SQLite + other drivers: portable conflict-target form on the
            // composite unique. The same constraint columns back the
            // portable index Task 9 declares via `$table->unique([...],
            // 'fiscal_event_projections_event_projector_unique')`.
            $sql = sprintf(
                'INSERT INTO "fiscal_event_projections" (%s) VALUES %s '.
                'ON CONFLICT (fiscal_event_id, projector_name) DO NOTHING',
                $columnList,
                $valuesSql,
            );
        }

        return $this->db->affectingStatement($sql, $bindings);
    }

    /**
     * Dispatch one `ApplyFiscalEventProjectionJob` per `pending` row.
     * `running` / `applied` / `dead_lettered` rows are filtered out — they
     * are owned by Horizon's lifecycle (Task 23). The dispatch is best-
     * effort idempotent: Task 23's `WithoutOverlapping` middleware drops
     * a duplicate that arrives while a sibling worker holds the cache
     * lock; outside that window, the job's own T_lock lifecycle short-
     * circuit handles re-delivery on a terminal-state row.
     *
     * @return int the number of jobs dispatched
     */
    private function dispatchPendingRows(FiscalEvent $event): int
    {
        $rowIds = $this->db->table('fiscal_event_projections')
            ->where('fiscal_event_id', $event->id)
            ->where('projection_status', ProjectionStatus::Pending->value)
            ->pluck('id');

        if ($rowIds->isEmpty()) {
            return 0;
        }

        foreach ($rowIds as $rowId) {
            // Driver always returns string from the `uuid` column; the
            // `pluck` upcasts safely under both PG + SQLite.
            ApplyFiscalEventProjectionJob::dispatch((string) $rowId);
        }

        return $rowIds->count();
    }

    /**
     * Resolve the underlying PDO driver name (`'pgsql'` / `'sqlite'` / ...).
     *
     * Mirrors `OutboxIngestor::driverName()` — `ConnectionInterface` does
     * not expose `getDriverName()`; that method lives on the concrete
     * `\Illuminate\Database\Connection`. Narrow with `instanceof` so
     * PHPStan level 8 stays clean; a hypothetical test that injects a
     * fake `ConnectionInterface` falls back to the default connection's
     * driver name.
     */
    private function driverName(): string
    {
        if ($this->db instanceof Connection) {
            return $this->db->getDriverName();
        }

        return DB::connection()->getDriverName();
    }
}
