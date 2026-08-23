<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Triage lane F4 (finding DS-5) — index `documents.source_document_id`.
 *
 * `2025_11_30_080000_create_documents_table.php:33` declares the column as a
 * bare `uuid()->nullable()`, and that line is the ONLY mention of
 * `source_document_id` in the entire tenant migration set — the two later
 * `documents` index migrations (`2026_01_02_add_created_at_index_to_documents`,
 * `2026_07_14_120000_add_expense_analytics_documents_index`) name it nowhere.
 * Verified empirically against a freshly migrated tenant schema: zero rows in
 * `pg_indexes` for `documents` whose definition mentions the column.
 *
 * The r2f4 correcting-entry lane then made the column HOT without adding an
 * index — `CorrectingEntryService.php:23` calls it "set at creation and never
 * editable afterwards" and `:215` "the sole input to" the correction lookup.
 * The triage counts 14 unindexed query predicates on it; this lane's own sweep
 * of `app/` found 15, several on the hot path:
 *   Accounting/Application/Services/AccountingService.php:1451
 *   Document/Domain/Document.php:435
 *   Document/Domain/Services/DeliveredQuantityResolver.php:299,491
 *   Document/Domain/Services/DocumentPostingService.php:437
 *   Document/Presentation/Controllers/CorrectingEntryController.php:55
 *   Procurement/Application/SupplierCreditNotePostingService.php:520
 * Every one of them is a sequential scan on `documents` today.
 *
 * The in-tree justification precedent is
 * `2026_08_08_120000_create_repository_adjustments_table.php:94-96`:
 *   "`journal_entry_id` gets a plain index — Postgres does not auto-index FK
 *    columns, so the GL→document lookup would otherwise be a sequential scan."
 * The same reasoning applies here, with the column not even carrying an FK.
 *
 * PARTIAL, not full. Of the 15 predicates, 14 are `where(...)` / `whereIn(...)`
 * on concrete uuid values and one is `whereNotNull(...)`
 * (`Expense/Presentation/Controllers/ExpenseController.php:338`). There is NO
 * `whereNull('source_document_id')` predicate anywhere in `app/`. Postgres
 * proves `source_document_id = $1` implies `source_document_id IS NOT NULL`
 * (the operator is strict), so a partial index serves the equality/IN
 * predicates as well as a full one and matches the `whereNotNull` predicate
 * exactly — while excluding the NULL majority (standalone quotes, orders and
 * invoices that derive from no source document), which keeps the index small.
 * The one call site that could pass NULL guards it first
 * (`Document.php:434` — `if ($this->source_document_id !== null)`), and a
 * `= NULL` comparison matches no row under SQL semantics regardless.
 *
 * NO foreign key. DS-5's FK + delete-policy half is DS-1-shaped (a VALIDATING
 * constraint that can be violated by existing data) and is explicitly deferred
 * to the C-8 trigger-widening lane. This migration is the index half only:
 * an index cannot be violated by data, so it is unconditionally safe to apply.
 *
 * CONCURRENTLY + `$withinTransaction = false`: PostgreSQL refuses
 * `CREATE INDEX CONCURRENTLY` inside a transaction block, and Laravel wraps a
 * migration in one unless the migration opts out via this property. Both are
 * the established idiom here — 13 tenant migrations already pair them, most
 * directly `2026_08_18_000001_add_delivery_note_uninvoiced_index.php`, which
 * builds a partial index on this very table. CONCURRENTLY also avoids taking
 * an ACCESS EXCLUSIVE lock on `documents` while tenants are live.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS documents_source_document_id_idx
            ON documents (source_document_id)
            WHERE source_document_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS documents_source_document_id_idx');
    }
};
