<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert fiscal-hash-input JSON columns from `jsonb` to `json` on PostgreSQL.
 *
 * Why: several fiscal hash chains compute a SHA-256 over `json_encode($array)`
 * of a column's contents, then VERIFY the chain by reloading the row and
 * recomputing the same hash. PostgreSQL `jsonb` does NOT preserve object key
 * insertion order (it stores keys sorted by length then bytewise), so the
 * reloaded value serialises to a different byte string than the in-memory
 * value that was hashed at write time. The recomputed hash therefore never
 * matches the stored hash and chain verification fails — a production-breaking
 * defect that is invisible on SQLite (which stores JSON as order-preserving
 * TEXT).
 *
 * The affected columns also feed a documented cross-language byte-for-byte
 * hash contract with the Tauri/JS POS client (see HashGoldenByteTest), whose
 * canonical form is the key INSERTION order. We therefore must preserve order
 * rather than canonicalise to sorted order. `json` preserves the stored text
 * verbatim, matching both the in-memory value and the cross-language contract.
 *
 * Affected hash-input columns:
 *   - pos_z_reports.report_data            (ZReportHashService::serializeForHashing)
 *   - pos_grandtotal_events.period_totals  (GrandtotalService::calculateHash)
 *   - pos_grandtotal_events.perpetual_totals
 *
 * None of these columns are queried with jsonb-specific SQL operators
 * (->, ->>, @>, jsonb_path_*); all access is via Eloquent's `array` cast in
 * PHP, so dropping the jsonb indexing capability is safe.
 *
 * No-op on SQLite (no jsonb/json type distinction; JSON is stored as TEXT).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_z_reports ALTER COLUMN report_data TYPE json USING report_data::json');
        DB::statement('ALTER TABLE pos_grandtotal_events ALTER COLUMN period_totals TYPE json USING period_totals::json');
        DB::statement('ALTER TABLE pos_grandtotal_events ALTER COLUMN perpetual_totals TYPE json USING perpetual_totals::json');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pos_z_reports ALTER COLUMN report_data TYPE jsonb USING report_data::jsonb');
        DB::statement('ALTER TABLE pos_grandtotal_events ALTER COLUMN period_totals TYPE jsonb USING period_totals::jsonb');
        DB::statement('ALTER TABLE pos_grandtotal_events ALTER COLUMN perpetual_totals TYPE jsonb USING perpetual_totals::jsonb');
    }
};
