<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * R-2 / LEDGER D-T9-1 — deferred document numbering: schema guard + census.
 *
 * THE CODE CHANGE THIS ACCOMPANIES. `documents.document_number` is now NULL for
 * every `Draft`; the number is allocated on the first transition out of `Draft`
 * (`DocumentStatusService::numberAllocationFor()`). Before it, a number was
 * spent when the ROW was created, so a form that reached one line and was then
 * abandoned held it forever — the wave-4 `PO-2026-0001 … PO-2026-0009` orphan
 * drafts sitting ahead of the operator's real `PO-2026-0010`.
 *
 * IT CHANGES NO DATA. Two halves, both deliberately conservative:
 *
 *  1. NULLABILITY — self-guarding. `document_number` was already made nullable
 *     by `2025_12_30_085029_make_document_number_nullable_on_documents_table`,
 *     so on every tenant that has run that migration this half is a no-op. It
 *     is re-asserted here (read from `information_schema` first, ALTER only if
 *     the column is still `NOT NULL`) so a tenant provisioned from an older
 *     baseline cannot take the new code with a schema that refuses it.
 *
 *  2. UNIQUENESS — read, reported, NOT duplicated. The design asked for a
 *     partial unique index on `(company_id, type, document_number) WHERE
 *     document_number IS NOT NULL`. The table already carries
 *     UNIQUE `(tenant_id, type, document_number)`, and:
 *       - tenant-scoped uniqueness IMPLIES company-scoped uniqueness (a company
 *         belongs to exactly one tenant), so a second index would buy nothing
 *         and cost a write on every document insert; and
 *       - PostgreSQL already treats NULLs as distinct in a unique index, so the
 *         existing one tolerates any number of unnumbered drafts without a
 *         `WHERE` clause.
 *     The partial index is therefore created ONLY if no unique index over
 *     `(type, document_number)` is found at all — the "if not already present"
 *     the design asked for, answered from `pg_indexes` rather than from memory.
 *
 * 3. CENSUS — non-mutating. Existing DRAFTS that already hold a number are NOT
 *    stripped: they may be printed, quoted, referenced by a customer, or linked
 *    from another document, and taking a number back is a bigger fiscal act than
 *    the one this lane is authorised to make. `DocumentStatusService` leaves any
 *    number a row already holds alone, so those drafts confirm normally, keeping
 *    the number they were shown with. What the operator gets instead is the
 *    list: per type, how many numbered drafts stand, and the numbers themselves
 *    (capped), so the sequence gaps that follow are explainable rather than
 *    mysterious.
 */
return new class extends Migration
{
    /** Numbers printed per document type before the line is truncated. */
    private const CENSUS_NUMBER_CAP = 25;

    public function up(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        $this->ensureNullable();
        $this->ensureUniquenessOverNumberedRows();
        $this->census();
    }

    public function down(): void
    {
        // Nothing to reverse: this migration changes no data, and re-imposing
        // NOT NULL on `document_number` would reject every unnumbered draft the
        // new code authors. The nullability itself is owned by
        // `2025_12_30_085029_make_document_number_nullable_on_documents_table`.
    }

    /**
     * Re-assert that a DRAFT may carry no number. No-op wherever it already can.
     */
    private function ensureNullable(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $isNotNull = $connection->selectOne(
            "SELECT is_nullable FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'documents'
               AND column_name = 'document_number'"
        );

        if ($isNotNull === null) {
            return;
        }

        if (((array) $isNotNull)['is_nullable'] === 'NO') {
            $connection->statement('ALTER TABLE documents ALTER COLUMN document_number DROP NOT NULL');

            Log::info('[R-2] documents.document_number made nullable (tenant was on a pre-2025-12-30 baseline).');
        }
    }

    /**
     * Create the partial unique index ONLY where nothing already enforces
     * uniqueness over `(type, document_number)`. See the class docblock.
     */
    private function ensureUniquenessOverNumberedRows(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $existing = $connection->select(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema() AND tablename = 'documents'"
        );

        foreach ($existing as $index) {
            $definition = (string) (((array) $index)['indexdef'] ?? '');

            if (str_contains($definition, 'UNIQUE') && str_contains($definition, 'document_number')) {
                return; // Already covered — do not duplicate.
            }
        }

        $connection->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS documents_company_type_number_unique
             ON documents (company_id, type, document_number)
             WHERE document_number IS NOT NULL'
        );

        Log::info('[R-2] created partial unique index documents_company_type_number_unique.');
    }

    /**
     * Report — never touch — the drafts that already hold a number.
     */
    private function census(): void
    {
        $connection = DB::connection();

        $rows = $connection->table('documents')
            ->select(['type', 'document_number'])
            ->where('status', 'draft')
            ->whereNotNull('document_number')
            ->orderBy('type')
            ->orderBy('document_number')
            ->get();

        if ($rows->isEmpty()) {
            Log::info('[R-2] numbered-draft census: 0 — every draft in this tenant is already unnumbered.');

            return;
        }

        /** @var array<string, list<string>> $byType */
        $byType = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $columns */
            $columns = (array) $row;
            $byType[(string) ($columns['type'] ?? 'unknown')][] = (string) ($columns['document_number'] ?? '');
        }

        Log::warning(sprintf(
            '[R-2] numbered-draft census: %d draft(s) still hold a document number. '
            .'They are NOT stripped — they keep the number they were shown with and confirm normally. '
            .'Every number below is a sequence position that is already spent; a gap after it is explained, not lost.',
            $rows->count(),
        ));

        foreach ($byType as $type => $numbers) {
            $shown = array_slice($numbers, 0, self::CENSUS_NUMBER_CAP);
            $suffix = count($numbers) > self::CENSUS_NUMBER_CAP
                ? sprintf(' … (+%d more)', count($numbers) - self::CENSUS_NUMBER_CAP)
                : '';

            Log::warning(sprintf(
                '[R-2]   %s: %d — %s%s',
                $type,
                count($numbers),
                implode(', ', $shown),
                $suffix,
            ));
        }
    }
};
