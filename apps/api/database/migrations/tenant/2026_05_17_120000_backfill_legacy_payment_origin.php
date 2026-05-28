<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Task 22 round-2 (Opus F5 P2) — backfill legacy `payments.origin`.
     *
     * Spec v7 §13 mandates that pre-existing `payments` rows (those
     * created before the Task 12 §13 columns were added) map to
     * `unknown_legacy`. The Task 12 migration
     * (`2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php`)
     * added the `origin` column as nullable but did NOT include the
     * backfill — so legacy rows landed with `origin = NULL` rather
     * than `unknown_legacy`. That divergence:
     *
     *   - leaves a reader-of-spec confused: a `SELECT origin, COUNT(*)
     *     FROM payments GROUP BY origin` shows NULLs where spec §13
     *     promised `unknown_legacy` rows.
     *   - subtly conflates "legacy row, origin unknown" with "post-
     *     Task 12 writer that forgot to stamp origin" (a §17.6 invariant
     *     violation) — `unknown_legacy` is the deliberate sentinel that
     *     separates the two cases.
     *
     * This migration closes the Task 12 spec drift. Idempotent: re-running
     * is a no-op because the WHERE predicate filters NULL rows only.
     *
     * No down() needed — the down() of the Task 12 migration drops the
     * `origin` column entirely, which makes the backfill's effect
     * automatically reversed when running migrations:reset.
     */
    public function up(): void
    {
        // Single SQL statement. Idempotent — re-runs touch zero rows.
        // No tenant/company scoping needed: the spec disposition is
        // global ("any pre-existing rows → unknown_legacy"); no
        // origin-stamped writer will create a NULL row going forward
        // (§17.6).
        DB::table('payments')
            ->whereNull('origin')
            ->update(['origin' => 'unknown_legacy']);
    }

    public function down(): void
    {
        // No-op. We cannot reliably distinguish "rows backfilled by this
        // migration" from "rows written after the migration that happen
        // to carry unknown_legacy" — and the Task 12 down() drops the
        // column anyway, so backfilling backward serves no purpose.
    }
};
