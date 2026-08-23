<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB backstop for the GL opening-balance BATCH posting (H-1b).
 *
 * `AccountingOpeningService::postBatch()` used to evaluate its `canPost()` guard on
 * an in-memory model read BEFORE the transaction opened, and the in-transaction
 * re-check read that same stale model — so two overlapping posts could both create a
 * `source_type='opening_balance'` journal entry for the SAME batch, and the (then
 * unconditional) lock-write re-pointed the batch hash chain at the second one. The
 * code fix is a `lockForUpdate()` re-read plus claim-style conditional writes; this
 * index is the database's own, code-independent refusal.
 *
 * WHY THE PREDICATE IS NARROWER THAN `source_type = 'opening_balance'`
 * -------------------------------------------------------------------
 * `source_type='opening_balance'` is NOT batch-exclusive. The per-product opening
 * flow (`ProductController::store`/`postOpening` -> `OpeningBalancePostingService`)
 * writes the same `source_type` with `source_id = <product id>`, and
 * `ResetOpeningBalanceService` deliberately supports reset-then-RE-ENTER for a
 * product — a supported flow, covered by
 * `ResetOpeningBalanceServiceTest::test_reset_reverses_opening_and_allows_reentry`,
 * which legitimately produces a SECOND journal entry with the same
 * (source_type, source_id) pair. A blanket partial index would break that flow in
 * production and fail closed on every tenant that has already used it.
 *
 * `entry_number LIKE 'OB-%'` is the honest discriminator: `OB-{year}-{seq}` is minted
 * ONLY by `AccountingOpeningService::generateEntryNumber()` — the batch arm. The
 * inventory arms mint `INV-OB-`/`INV-OBR-`, which do not match a left-anchored
 * `'OB-%'`. `~~(text,text)` is IMMUTABLE, so the predicate is legal in an index.
 *
 * The other two arms are backstopped elsewhere, by design, not by omission:
 *  - INVENTORY batch: `OpeningBalancePostingService` already refuses a second
 *    posting via its enter-once guard (`OpeningAlreadyExistsException` when an
 *    active, non-reversed opening movement exists for the product+location).
 *  - AR/AP batch: it writes N documents whose numbers are freshly minted per post,
 *    so no column of `documents` can carry a per-batch unique. Its DB-level
 *    guarantee is the claim-style conditional UPDATE on `opening_balance_batches`
 *    (`... SET status='VALIDATED' WHERE id=? AND status='DRAFT'`, asserting exactly
 *    one affected row) plus the per-row claim in `markRowsPosted()`.
 *
 * S-16 PRE-PROMOTION CENSUS OBLIGATION
 * ------------------------------------
 * `origin/dev` auto-migrates every tenant database on push, and this migration FAILS
 * CLOSED (it throws) if any tenant already holds a duplicate pair. Before promoting,
 * run the census below against EVERY tenant database and record the result in the
 * promotion ledger; a non-empty result must be dispositioned (the duplicate rows
 * reconciled or reversed) BEFORE the push, never by weakening the index:
 *
 *   SELECT source_type, source_id, COUNT(*) AS duplicate_count
 *   FROM journal_entries
 *   WHERE source_type = 'opening_balance' AND entry_number LIKE 'OB-%'
 *   GROUP BY source_type, source_id
 *   HAVING COUNT(*) > 1;
 *
 * Expected census: zero rows on every tenant. A duplicate here means a batch was
 * double-posted before this hardening landed, and its opening equity is overstated —
 * an accounting correction, not a migration problem.
 *
 * Structure (pre-flight duplicate scan, pgsql-only, IF NOT EXISTS, self-guarding
 * re-runnable shape) mirrors
 * `2026_08_11_000100_unique_journal_entries_source_inventory_movement.php` verbatim.
 */
return new class extends Migration
{
    private const INDEX = 'uniq_je_source_opening_balance_batch';

    /**
     * Left-anchored, IMMUTABLE predicate identifying GL opening-BATCH entries.
     */
    private const PREDICATE = "source_type = 'opening_balance' AND entry_number LIKE 'OB-%'";

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $duplicates = DB::select(
            'SELECT source_type, source_id, COUNT(*) AS duplicate_count
             FROM journal_entries
             WHERE '.self::PREDICATE.'
             GROUP BY source_type, source_id
             HAVING COUNT(*) > 1'
        );

        if ($duplicates !== []) {
            $first = $duplicates[0];
            throw new RuntimeException(sprintf(
                'Cannot create %s: duplicate GL opening-balance batch posting (%s, %s). '
                .'A batch was double-posted before the lifecycle hardening landed; reconcile the '
                .'duplicate journal entry before migrating.',
                self::INDEX,
                (string) $first->source_type,
                (string) $first->source_id,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON journal_entries (source_type, source_id) WHERE '.self::PREDICATE
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
