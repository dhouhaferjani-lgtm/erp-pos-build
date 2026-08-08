<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Document-per-action remediation, lane G3 — ONE shift-variance adjustment
 * document per POS shift.
 *
 * `CashCountRecorded` fires from BOTH the live path
 * (`ReportGenerationService::generateZReport`) and the offline replay path
 * (`ZReportSyncController::sync`), and an offline device may re-sync the same
 * Z report any number of times. `PostShiftCashVarianceAdjustment` therefore
 * DERIVES its document id from the shift id (UUIDv5) so a replay resolves to
 * the existing row through the service's `firstOrCreate` — V3's discriminator
 * pattern. This index is the database-level backstop for that invariant, in the
 * same spirit as
 * `2026_08_08_120100_unique_journal_entries_source_repository_adjustment.php`:
 * even a caller that mints its own id cannot land a SECOND shift-variance
 * document on a shift that already has one.
 *
 * PARTIAL, on purpose: the interactive adjustment endpoint has no shift and
 * writes `pos_shift_id = NULL`, and there are arbitrarily many of those.
 *
 * NO driver guard (matching the I1 exemplar): sqlite supports partial indexes,
 * and guarding would silently strip the coverage from the fast test loop.
 *
 * Backfill safety: `pos_shift_id` shipped NULL-only with the V3 table
 * (`2026_08_08_120000`) — no tenant can already hold two rows sharing one shift
 * id, so the index can be created against live data unattended. `IF NOT EXISTS`
 * keeps a re-run of a partially applied `tenants:migrate` batch a no-op
 * (CLAUDE.md: a push to origin/dev auto-deploys migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS repository_adjustments_pos_shift_unique
            ON repository_adjustments (pos_shift_id)
            WHERE pos_shift_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS repository_adjustments_pos_shift_unique');
    }
};
