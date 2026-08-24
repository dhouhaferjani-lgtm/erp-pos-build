<?php

declare(strict_types=1);

use App\Modules\Document\Domain\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * N-6 — put the `documents.status` enum in the DATABASE, next to the fiscal
 * CHECKs `documents` already carries
 * (`2025_12_11_054337_add_fiscal_constraints_to_documents.php:46,53` —
 * `fiscal_category IN (…)`, `fiscal_status IN (…)`).
 *
 * The omission was deliberate and documented
 * (`2026_06_26_110000_add_supplier_credit_note_reason_to_documents.php:19-20`:
 * "the enum cast on the Document model enforces the allowed set … SQLite-
 * compatible for feature tests"), but the cast is an APPLICATION guard and this
 * lane exists because an application guard was bypassed. A CHECK is the backstop
 * that survives a raw UPDATE, a backfill script and a psql session — the three
 * writers no PHPStan rule and no service can reach.
 *
 * SCOPE — VALUE DOMAIN ONLY, NOT THE ADJACENCY. The transition graph lives in
 * `DocumentStatusMachine`; encoding edges in SQL would need the OLD row and
 * therefore a trigger, and `documents` already has one
 * (`trg_document_immutability`) whose widening is a separate, owner-tracked lane
 * (C-8). This constraint answers only "is this a status value that exists?".
 *
 * PG-ONLY (`pgsql` guard) per the tenant-migration convention: the SQLite test
 * driver never sees it, exactly like the 95 of 110 CHECK-bearing migrations that
 * already guard this way.
 *
 * MIGRATION-BEARING — a CHECK an existing row could violate. The pre-flight
 * census below runs INSIDE the migration and ABORTS the tenant rather than
 * letting Postgres fail the DDL with a message that names no row. Per-tenant
 * census query to run BEFORE the fleet migration:
 *
 *   SELECT status, COUNT(*)
 *     FROM documents
 *    WHERE status IS NULL
 *       OR status NOT IN ('draft','confirmed','posted','paid','received','cancelled')
 *    GROUP BY status;
 *
 * Expect zero rows: `status` is written exclusively through the `DocumentStatus`
 * cast today. A non-empty result means a raw writer exists and must be found
 * before this constraint is applied — which is the whole point of the census.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'chk_documents_status_enum';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('documents')) {
            return;
        }

        $values = $this->quotedValues();

        $violations = DB::select(
            "SELECT COALESCE(status, '<NULL>') AS status, COUNT(*) AS violation_count
               FROM documents
              WHERE status IS NULL OR status NOT IN ({$values})
              GROUP BY status"
        );

        if ($violations !== []) {
            $first = $violations[0];
            throw new RuntimeException(sprintf(
                'Cannot create %s: documents.status carries %s row(s) with the unknown value "%s". '
                .'Find the writer before applying this constraint.',
                self::CONSTRAINT,
                (string) $first->violation_count,
                (string) $first->status,
            ));
        }

        DB::statement(
            'ALTER TABLE documents DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT
        );
        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (status IN ({$values}))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }

    /**
     * Derived from the enum, never hand-listed: a case added to
     * `DocumentStatus` without touching this file would otherwise be rejected by
     * a constraint written from memory.
     */
    private function quotedValues(): string
    {
        return implode(', ', array_map(
            static fn (DocumentStatus $status): string => "'".$status->value."'",
            DocumentStatus::cases(),
        ));
    }
};
