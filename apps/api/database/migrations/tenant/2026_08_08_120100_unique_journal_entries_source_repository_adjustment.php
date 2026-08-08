<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gate finding I1 (DPA lane V3) — ONE posted journal entry per repository
 * adjustment.
 *
 * `createRepositoryAdjustmentJournalEntry()` used to run unconditionally, ahead
 * of `TreasuryMovementService::record()`'s idempotent-replay short-circuit, so a
 * replayed adjustment id could mint a SECOND posted `repository_adjustment`
 * journal entry with no compensating movement and no document pointing at it.
 * The controller now reuses the document's already-posted entry on replay; this
 * index is the database-level backstop for that invariant — the same guard the
 * house already applies to the other 1:1 source documents:
 *   - 2026_07_12_100000_unique_journal_entries_source_treasury_transfer.php
 *   - 2026_06_26_120000_unique_journal_entries_source_procurement.php
 *
 * STATUS-SCOPED deliberately, exactly as the treasury-transfer exemplar is:
 * `createRepositoryAdjustmentJournalEntry()` inserts a Draft first and
 * `postEntryNow()` promotes it, so an unscoped index would fire at the DRAFT
 * insert — outside any recovery path — and break the flow rather than guard it.
 *
 * Backfill safety: before this lane every request minted a fresh adjustment
 * UUID, so no tenant can already hold two POSTED entries sharing one
 * `repository_adjustment` source_id; the index can be created against live data
 * unattended. `IF NOT EXISTS` keeps a re-run of a partially applied
 * `tenants:migrate` batch a no-op.
 *
 * NO driver guard (matching both exemplars): sqlite supports partial indexes,
 * and guarding would silently strip the coverage from the fast test loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS journal_entries_repository_adjustment_source_unique
            ON journal_entries (source_type, source_id)
            WHERE source_type = 'repository_adjustment' AND status = 'posted'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS journal_entries_repository_adjustment_source_unique');
    }
};
