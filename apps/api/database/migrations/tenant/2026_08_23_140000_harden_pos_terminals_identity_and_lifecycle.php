<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Session B lane Q-7 — the DB backstops `pos_terminals` never got.
 *
 * `pos_terminals` carries the NF525 hash-chain head (`genesis_seed`,
 * `current_sequence`, `current_year`, `last_hash`) and the device lifecycle
 * (`is_active`, `activated_at`, `deactivated_at`, `deactivation_reason`). Its
 * same-day sibling `pos_shifts` (`2026_01_08_190641`) was given a full set of
 * invariants — `pos_shifts_status` (`:81`), `pos_shifts_closed_logic` (`:84`),
 * `pos_shifts_positive_amounts` (`:72`), `pos_shifts_variance_calc` (`:77`) and
 * the partial unique `pos_shifts_one_open_per_terminal` (`:69`). The terminal
 * table got two format CHECKs and one composite unique on `code`, and nothing
 * else. This migration closes the three gaps the 2026-08-23 state-machine sweep
 * named (finding #25 plus the Fiscal and POS-till sub-reports' twin HIGH).
 *
 * ── LEG 1 · `pos_terminals_unique_hardware_identifier` ────────────────────────
 * Partial UNIQUE on `(tenant_id, company_id, hardware_identifier)` WHERE the
 * identifier is set and the row is not soft-deleted.
 *
 * `hardware_identifier` is the ONLY link between a fiscal chain and the physical
 * till authoring into it, and it carried no index at all. Two devices could
 * claim one terminal (the claim was an unlocked check-then-set) and one device
 * could hold N terminals (`requestTerminal()` wrote the column through
 * `Terminal::create()` with no collision check whatsoever). Two tills authoring
 * against one `terminal_id` collide on the `fiscal_events` chain key
 * `(tenant_id, company_id, terminal_id, chain_context, sequence_number)` — the
 * ingestor correctly refuses them and parks real sales in
 * `fiscal_event_quarantine` as `sequence_conflict`. The application layer now
 * settles the claim with a conditional UPDATE; this index is what makes that
 * settlement true under concurrency rather than merely likely.
 *
 * SCOPE, deliberately per-company and not per-tenant: `TerminalDeviceLookupTest
 * ::test_find_by_device_scoped_to_company` pins that a same-hardware row in
 * ANOTHER company of the same tenant is invisible to this company's lookup, so
 * that row is legal and must stay legal.
 *
 * PREDICATE `deleted_at IS NULL`: `pos_terminals` has soft deletes (`:56`) and
 * `TerminalController::archive()` is a soft delete. Excluding archived rows is
 * what lets a replaced till re-register its hardware without first purging the
 * history the fiscal chain depends on.
 *
 * CREATED ON BOTH DRIVERS. Partial unique indexes are native to PostgreSQL and
 * to SQLite 3.8+; the precedent in this tree is
 * `2026_07_08_100400_add_chain_sequence_unique_index_to_journal_entries`, which
 * deliberately creates on both so the default (SQLite) test harness exercises
 * the real backstop. Legs 2 and 3 are `ALTER TABLE … ADD CONSTRAINT`, which
 * SQLite cannot do, and are therefore pgsql-guarded.
 *
 * ── LEG 2 · `pos_terminals_type` ─────────────────────────────────────────────
 * CHECK whitelisting the three `App\Modules\POS\Domain\Enums\TerminalType`
 * cases. `type` is `string(20)` (`2026_02_19_000002:16`) and unconstrained,
 * while TWO partial unique indexes on the same table key off its exact literals
 * — `pos_terminals_unique_web_per_location` (`… WHERE type = 'web'`) and
 * `pos_terminals_unique_virtual_admin_per_company` (`… WHERE type =
 * 'virtual_admin'`). A typo'd value does not merely store badly: it silently
 * escapes the uniqueness those two indexes exist to enforce.
 *
 * The values are spelled as LITERALS, not `TerminalType::Web->value`, for the
 * reason the B-3 backfill spells its own list literally: a migration is a
 * historical record of what ran on the day it ran. Widening the enum therefore
 * requires a NEW migration widening this CHECK — which is the safe direction,
 * because the failure mode is a loud INSERT rejection rather than a silent hole.
 *
 * ── LEG 3 · `pos_terminals_active_logic` ─────────────────────────────────────
 * The state invariant #25 asked for, mirroring `pos_shifts_closed_logic`. The
 * legal combinations were derived from the writers, not assumed:
 *
 *   `store()` / `getOrCreateWebTerminal()` (`TerminalController.php:133,514`)
 *        is_active = true,  activated_at = now, deactivated_at = NULL, reason NULL
 *   `activate()` (`:243-248`)
 *        is_active = true,  activated_at = now, deactivated_at = NULL, reason NULL
 *   `deactivate()` (`:289-293`)
 *        is_active = false, deactivated_at = now, reason = the request's reason
 *        — which DEFAULTS TO THE EMPTY STRING when the caller omits it (`:287`)
 *   `requestTerminal()` (`:431`)
 *        is_active = FALSE with deactivated_at NULL and reason NULL — a terminal
 *        that has never been activated, awaiting admin approval
 *   `VirtualAdminTerminalResolver::resolve():50`
 *        is_active = true, no deactivation columns
 *
 * The fourth row is why the naive invariant (`is_active = false ⇒
 * deactivated_at IS NOT NULL`) is WRONG here and would have rejected every
 * device-requested terminal. What is actually invariant is the pair:
 *
 *   (a) an ACTIVE terminal carries no deactivation record at all, and
 *   (b) a deactivation REASON never exists without a deactivation MOMENT.
 *
 * Together these outlaw exactly the states the sweep named — `is_active = true`
 * with `deactivated_at` set, and its reverse — while leaving both legitimate
 * `is_active = false` shapes reachable. The empty-string reason `deactivate()`
 * writes stays legal (it is paired with a timestamp).
 *
 * ── PRE-FLIGHT SCANS AND THE FLEET-ABORT DECISION ────────────────────────────
 * ALL THREE legs can fail on rows that already exist. `tenants:migrate` runs
 * unattended on every tenant database on push, so each leg carries its own
 * pre-flight violation scan and, on a hit, REPORTS `status=BLOCKED` at ERROR
 * level with the offending count and a sample, then SKIPS THAT LEG ONLY. The
 * other legs still apply.
 *
 * This is a deliberate divergence from the `uniq_je_source_inventory_movement`
 * precedent (`2026_08_11_000100`), which throws. That migration guards a
 * money-duplication invariant where a human MUST intervene before the deploy
 * proceeds. Here, throwing would abort the whole tenant's migration run inside
 * the migrator's transaction, leaving the `migrations` bookkeeping row unwritten
 * and every LATER migration blocked for that tenant — a tenant-wide outage in
 * exchange for a backstop against a hole that this same deploy closes in the
 * application layer anyway (`claim()`'s conditional UPDATE, `requestTerminal()`'s
 * collision check). A missing index on one legacy tenant is recoverable and
 * loud; a bricked tenant migration chain is neither. The gate token is the
 * deploy checklist's grep target, and the per-tenant census query in the lane
 * report is how the fleet is measured BEFORE the deploy rather than after.
 *
 * ── BOTH FAILURE STATUSES ARE PERMANENT UNTIL A HUMAN ACTS (C-2) ─────────────
 * A leg reports exactly one of `status=ok`, `status=BLOCKED` or `status=FAILED`.
 * `BLOCKED` is the pre-flight scan refusing to attempt DDL it knows existing
 * rows would reject; `FAILED` is the DDL itself throwing (`run()` catches
 * `Throwable`, logs, and returns — `:339-347`). THE TWO END IN THE SAME PLACE:
 * `up()` completes normally either way, so the migrator writes the `migrations`
 * bookkeeping row and NEITHER leg is ever retried on any later deploy. A FAILED
 * leg is therefore not "will be picked up next time" — it is a permanently
 * absent constraint on that tenant until it is re-applied by hand, exactly like
 * a BLOCKED one. The swallow is deliberate and stays (see the fleet-abort
 * reasoning above); what must not happen is a deploy that greps only for
 * BLOCKED and reports the fleet clean.
 *
 * DEPLOY-CHECKLIST GREP — run over the tenants:migrate log, verbatim:
 *
 *   grep -E 'POS TERMINAL IDENTITY/LIFECYCLE HARDENING:.*status=(BLOCKED|FAILED)' <log>
 *
 * Any hit names `tenant=<key> leg=<leg>`; resolve the offending rows (BLOCKED)
 * or the underlying error (FAILED) and re-apply that leg's DDL manually for
 * that tenant. `up()` is idempotent (`CREATE … IF NOT EXISTS`, `DROP CONSTRAINT
 * IF EXISTS` before each `ADD`), so a manual re-run is safe. Until then the
 * application-layer guards in `TerminalController` are the only enforcement.
 *
 * `NOT VALID` + a later `VALIDATE CONSTRAINT` was considered for the two CHECKs
 * and rejected: `NOT VALID` admits the constraint while permanently exempting
 * the rows that violate it, so the tenant would carry an invariant that is a
 * lie about its own data — worse than a named, logged, absent constraint. The
 * table is a handful of rows per tenant, so the validating `ADD CONSTRAINT`'s
 * ACCESS EXCLUSIVE lock is momentary.
 *
 * IDEMPOTENT: `CREATE UNIQUE INDEX IF NOT EXISTS`, and `DROP CONSTRAINT IF
 * EXISTS` before each `ADD CONSTRAINT`. Re-running `up()` is safe, which is what
 * lets `ProvesTenantMigrationRoundTrip` prove the round trip repeats.
 *
 * CONTAINED FAILURE: every DDL statement runs inside `DB::transaction()`, which
 * opens a SAVEPOINT when a transaction is already active (it always is, under
 * the migrator and under `RefreshDatabase`). A caught `QueryException` on
 * PostgreSQL would otherwise leave the enclosing transaction aborted (SQLSTATE
 * 25P02) and kill the tenant's entire run at the bookkeeping INSERT — the same
 * trap the B-3 backfill documents.
 */
return new class extends Migration
{
    /**
     * Deploy-gate token. One line per tenant per leg, at WARNING (ok) or ERROR
     * (blocked/failed) level — production runs LOG_LEVEL=warning and drops info
     * entirely, so an info-level gate line would let a checklist's grep pass
     * against an empty log.
     */
    private const GATE_TOKEN = 'POS TERMINAL IDENTITY/LIFECYCLE HARDENING:';

    private const UNIQUE_INDEX = 'pos_terminals_unique_hardware_identifier';

    private const TYPE_CHECK = 'pos_terminals_type';

    private const LIFECYCLE_CHECK = 'pos_terminals_active_logic';

    /**
     * `App\Modules\POS\Domain\Enums\TerminalType` as of 2026-08-23. Literals on
     * purpose — see the class docblock, leg 2.
     *
     * @var list<string>
     */
    private const TERMINAL_TYPES = ['web', 'physical', 'virtual_admin'];

    /**
     * The lifecycle invariant, as a SQL predicate. Kept in one constant so the
     * pre-flight scan and the CHECK can never drift apart — a scan that tested
     * something other than the constraint it gates would be worse than no scan.
     */
    private const LIFECYCLE_PREDICATE = <<<'SQL'
        (is_active = false OR (deactivated_at IS NULL AND deactivation_reason IS NULL))
        AND (deactivation_reason IS NULL OR deactivated_at IS NOT NULL)
        SQL;

    public function up(): void
    {
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        if (! Schema::hasTable('pos_terminals')) {
            // REPORTS rather than returning silently: absence of the token line
            // is how a deploy gate detects a tenant that died mid-run, so a
            // silent skip is indistinguishable from a crash at the log.
            Log::warning(sprintf(
                '%s tenant=%s status=skipped reason=pos_terminals-table-absent.',
                self::GATE_TOKEN,
                $tenantKey,
            ));

            return;
        }

        $this->applyUniqueHardwareIdentifier($tenantKey);

        // Legs 2 and 3 are ALTER TABLE … ADD CONSTRAINT, which SQLite cannot do.
        if ($this->driver() === 'pgsql') {
            $this->applyTypeWhitelist($tenantKey);
            $this->applyLifecycleInvariant($tenantKey);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_terminals')) {
            return;
        }

        DB::connection($this->getConnection())->statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);

        if ($this->driver() === 'pgsql') {
            DB::connection($this->getConnection())
                ->statement('ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS '.self::TYPE_CHECK);
            DB::connection($this->getConnection())
                ->statement('ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS '.self::LIFECYCLE_CHECK);
        }
    }

    // ------------------------------------------------------------------ legs

    private function applyUniqueHardwareIdentifier(string $tenantKey): void
    {
        /** @var list<object{tenant_id: mixed, company_id: mixed, hardware_identifier: mixed, duplicate_count: mixed}> $duplicates */
        $duplicates = DB::connection($this->getConnection())->select(<<<'SQL'
            SELECT tenant_id, company_id, hardware_identifier, COUNT(*) AS duplicate_count
            FROM pos_terminals
            WHERE hardware_identifier IS NOT NULL
              AND deleted_at IS NULL
            GROUP BY tenant_id, company_id, hardware_identifier
            HAVING COUNT(*) > 1
            SQL);

        if ($duplicates !== []) {
            $first = $duplicates[0];
            $this->blocked($tenantKey, 'unique-hardware-identifier', count($duplicates), sprintf(
                'company=%s hardware_identifier=%s rows=%s',
                (string) $first->company_id,
                (string) $first->hardware_identifier,
                (string) $first->duplicate_count,
            ));

            return;
        }

        $this->run($tenantKey, 'unique-hardware-identifier', 'CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX.'
             ON pos_terminals (tenant_id, company_id, hardware_identifier)
             WHERE hardware_identifier IS NOT NULL AND deleted_at IS NULL');
    }

    private function applyTypeWhitelist(string $tenantKey): void
    {
        $types = $this->quotedTerminalTypes();

        /** @var list<object{type: mixed, violation_count: mixed}> $violations */
        $violations = DB::connection($this->getConnection())->select(
            "SELECT type, COUNT(*) AS violation_count
             FROM pos_terminals
             WHERE type IS NULL OR type NOT IN ({$types})
             GROUP BY type"
        );

        if ($violations !== []) {
            $first = $violations[0];
            $this->blocked($tenantKey, 'type-whitelist', count($violations), sprintf(
                'type=%s rows=%s',
                $first->type === null ? 'NULL' : (string) $first->type,
                (string) $first->violation_count,
            ));

            return;
        }

        // DROP-then-ADD rather than a bare ADD: the constraint name must be
        // re-assertable so a re-run (and the round-trip proof) stays safe.
        $this->run(
            $tenantKey,
            'type-whitelist',
            'ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS '.self::TYPE_CHECK,
            'ALTER TABLE pos_terminals ADD CONSTRAINT '.self::TYPE_CHECK." CHECK (type IN ({$types}))",
        );
    }

    private function applyLifecycleInvariant(string $tenantKey): void
    {
        $predicate = self::LIFECYCLE_PREDICATE;

        /** @var list<object{violation_count: mixed}> $violations */
        $violations = DB::connection($this->getConnection())->select(
            "SELECT COUNT(*) AS violation_count FROM pos_terminals WHERE NOT ({$predicate})"
        );

        $violationCount = $violations === [] ? 0 : (int) $violations[0]->violation_count;

        if ($violationCount > 0) {
            $this->blocked($tenantKey, 'active-lifecycle', $violationCount, 'incoherent is_active/deactivated_at/deactivation_reason rows');

            return;
        }

        $this->run(
            $tenantKey,
            'active-lifecycle',
            'ALTER TABLE pos_terminals DROP CONSTRAINT IF EXISTS '.self::LIFECYCLE_CHECK,
            'ALTER TABLE pos_terminals ADD CONSTRAINT '.self::LIFECYCLE_CHECK." CHECK ({$predicate})",
        );
    }

    // -------------------------------------------------------------- plumbing

    private function driver(): string
    {
        return DB::connection($this->getConnection())->getDriverName();
    }

    private function quotedTerminalTypes(): string
    {
        return implode(', ', array_map(
            static fn (string $type): string => "'".str_replace("'", "''", $type)."'",
            self::TERMINAL_TYPES,
        ));
    }

    /**
     * Runs one leg's DDL inside a SAVEPOINT and reports the outcome. Never
     * throws: one leg's failure must leave the other legs — and every other
     * tenant in the unattended run — untouched.
     *
     * C-2: swallowing means `up()` completes and the `migrations` bookkeeping
     * row is written, so a `status=FAILED` leg is PERMANENT UNTIL MANUALLY
     * RE-APPLIED — identical in consequence to `status=BLOCKED`, and never
     * retried by a later deploy. The deploy checklist must grep for both:
     *
     *   grep -E 'POS TERMINAL IDENTITY/LIFECYCLE HARDENING:.*status=(BLOCKED|FAILED)' <log>
     */
    private function run(string $tenantKey, string $leg, string ...$statements): void
    {
        try {
            DB::connection($this->getConnection())->transaction(function () use ($statements): void {
                foreach ($statements as $statement) {
                    DB::connection($this->getConnection())->statement($statement);
                }
            });

            Log::warning(sprintf(
                '%s tenant=%s leg=%s status=ok.',
                self::GATE_TOKEN,
                $tenantKey,
                $leg,
            ));
        } catch (Throwable $e) {
            Log::error(sprintf(
                '%s tenant=%s leg=%s status=FAILED reason=exception. %s',
                self::GATE_TOKEN,
                $tenantKey,
                $leg,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Reports a leg the pre-flight scan refused to attempt.
     *
     * C-2: same permanence as `status=FAILED` above — the leg is skipped, `up()`
     * completes, the migration is recorded as applied, and nothing re-runs it.
     * Both statuses share one grep:
     *
     *   grep -E 'POS TERMINAL IDENTITY/LIFECYCLE HARDENING:.*status=(BLOCKED|FAILED)' <log>
     */
    private function blocked(string $tenantKey, string $leg, int $groups, string $sample): void
    {
        Log::error(sprintf(
            '%s tenant=%s leg=%s status=BLOCKED violations=%d sample=[%s]. '.
            'The constraint was NOT created for this tenant — resolve the rows and re-apply it manually. '.
            'The application-layer guard in TerminalController still applies.',
            self::GATE_TOKEN,
            $tenantKey,
            $leg,
            $groups,
            $sample,
        ));
    }
};
