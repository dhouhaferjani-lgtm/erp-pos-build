<?php

declare(strict_types=1);

use App\Modules\Document\Domain\DTOs\FiscalAuthorityTypes;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalAuthorityMode;
use App\Modules\Document\Domain\Enums\FiscalAuthorityStatus;
use App\Modules\Document\Domain\Enums\PolicyExpertiseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Session C lane C-QR0a — the fiscal-authority dimension, in an UNACTIVATED state.
 * SPEC §1 (fiscal row) · §2.3 (storage + fail-safe) r11; program r11 condition 1.
 *
 * ── WHAT THIS SHIPS ──
 *  `country_document_settings`  (the document-lane country policy family's
 *  declared extension point — see `2026_08_10_120000_create_country_document_settings_table.php`):
 *    + `fiscal_authority_mode`     varchar(32)  NULL   ({@see FiscalAuthorityMode})
 *    + `fiscal_authority_types`    jsonb        NULL   ({@see FiscalAuthorityTypes})
 *    + `policy_expertise_status`   varchar(32)  NULL   ({@see PolicyExpertiseStatus})
 *  `documents`:
 *    + `fiscal_authority_status`   varchar(32)  NULL   ({@see FiscalAuthorityStatus})
 *
 * ── WHAT THIS DELIBERATELY DOES NOT SHIP ──
 * No DDL default, no NOT NULL, no CHECK, no seed row, no backfill, no index.
 * Two independent reasons, both binding:
 *
 *  1. **F-112 — no default may decide the policy.** `fiscal_authority_mode` is a
 *     compliance control (TN `required`, everything else `not_required`) that C-QR0b
 *     writes EXPLICITLY for every provisionable catalog country. A DDL default here
 *     would silently hand a mode to any country whose seed row is missing, which is
 *     precisely the failure the seeded-row + typed-refusal
 *     (`COUNTRY_DOCUMENT_SETTINGS_NOT_SEEDED`) design exists to prevent. Contrast
 *     `pre_delivery_invoicing_policy`, whose DDL default this lane does NOT touch and
 *     whose removal belongs to C-QR0b's complete-rows work (F-134).
 *
 *  2. **Staged deployment (F-111 / F-160).** Tenant migrations roll one tenant at a
 *     time (`RollingTenantMigrationCommand`), so between this lane and C-QR0b the
 *     fleet is MIXED and the RUNNING release still inserts documents without ever
 *     naming `fiscal_authority_status`. A NOT NULL or a CHECK shipped ahead of the
 *     backfill would break those inserts on exactly the tenants that migrated first.
 *     The CHECK arrives ATOMICALLY with the backfill in C-QR0b; nothing reads these
 *     columns before then except the Eloquent casts.
 *
 * ── MIGRATION-BEARING: additive nullable, safe on any row count ──
 * Every statement is `ADD COLUMN … NULL` with no default and no rewrite, so on
 * PostgreSQL 16 each is a catalog-only change (no table rewrite, no full-table
 * lock beyond a brief ACCESS EXCLUSIVE on the catalog entry) and completes in
 * constant time whether the tenant holds 0 or 10^8 documents. There is NO
 * constraint any existing row could violate, hence NO ROW-DRIVEN abort path: the
 * pre-flight census below is INFORMATIONAL — it sizes what C-QR0b will have to
 * backfill — and no row count can fail this migration.
 *
 * It aborts on exactly ONE condition (gate r1 F-4): a tenant missing either table
 * this migration extends. See `assertPrerequisiteTables()` — a migration that
 * cannot do its whole job must not record itself as done.
 *
 * PRE-FLIGHT CENSUS (read-only; run per tenant database before promoting).
 * Docblock-census pattern copied from
 * `2026_08_11_000100_unique_journal_entries_source_inventory_movement.php`; its
 * abort is retargeted from "a row would violate the constraint" to "the schema this
 * migration extends is not there", the only way this one can fail.
 *
 *   SELECT
 *     (SELECT COUNT(*) FROM country_document_settings)                        AS settings_rows,
 *     (SELECT COUNT(*) FROM documents)                                        AS document_rows,
 *     (SELECT COUNT(*) FROM documents WHERE status = 'posted')                AS posted_documents,
 *     (SELECT COUNT(*) FROM documents
 *       WHERE type IN ('invoice','credit_note') AND status <> 'cancelled')    AS authority_scope_documents,
 *     (SELECT COUNT(*) FROM information_schema.columns
 *       WHERE table_schema = current_schema()
 *         AND (table_name, column_name) IN (
 *               ('country_document_settings','fiscal_authority_mode'),
 *               ('country_document_settings','fiscal_authority_types'),
 *               ('country_document_settings','policy_expertise_status'),
 *               ('documents','fiscal_authority_status')))                     AS new_columns_present;
 *
 * `new_columns_present` is 0 before this migration and 4 after — the idempotence
 * check, and the ONLY per-tenant proof that the schema is actually there. "The
 * migration is recorded as run" is NOT that proof, which is why C-QR0b's preflight
 * must assert this query returns 4 per tenant before it seeds anything (gate ruling
 * R-2). The executed census emits both halves — `new_columns_before` /
 * `new_columns_after` — so a first application (0 -> 4) is distinguishable in the log
 * from a re-run (4 -> 4). `authority_scope_documents` is the C-QR0b initialiser's
 * population and the OQ-49 quarantine's upper bound; it does not gate this migration.
 *
 * Fleet-abort risk: NONE from data. A tenant missing `country_document_settings`
 * aborts loudly and is reported by the rolling command, by design. Unattended-safe
 * (it either applies completely or not at all), idempotent, guarded per object, and
 * rollback-neutral (`down()` drops only what `up()` added; nothing reads the
 * columns, so a rollback loses no state).
 */
return new class extends Migration
{
    /**
     * Wide enough for every case of all three enums (`not_required` is the longest
     * at 12 characters), and matched to the family's existing `varchar(32)` policy
     * columns rather than invented.
     */
    private const POLICY_COLUMN_LENGTH = 32;

    public function up(): void
    {
        $this->assertPrerequisiteTables();

        $columnsBefore = $this->countNewColumns();

        if (Schema::hasTable('country_document_settings')) {
            Schema::table('country_document_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('country_document_settings', 'fiscal_authority_mode')) {
                    $table->string('fiscal_authority_mode', self::POLICY_COLUMN_LENGTH)->nullable();
                }

                if (! Schema::hasColumn('country_document_settings', 'fiscal_authority_types')) {
                    // `jsonb`, NOT `json` (gate r1 F-2). PostgreSQL's `json` type has no
                    // equality operator — `'["invoice"]'::json = '["invoice"]'::json`
                    // raises 42883 — so the canonical form this column stores could not
                    // be compared in SQL at all, and SQLite (where `json` is TEXT and
                    // `=` works) would hide it from the default test driver. `jsonb` is
                    // also the tenant schema's house convention, 99 columns to 26.
                    $table->jsonb('fiscal_authority_types')->nullable();
                }

                if (! Schema::hasColumn('country_document_settings', 'policy_expertise_status')) {
                    $table->string('policy_expertise_status', self::POLICY_COLUMN_LENGTH)->nullable();
                }
            });
        }

        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('documents', 'fiscal_authority_status')) {
                    $table->string('fiscal_authority_status', self::POLICY_COLUMN_LENGTH)->nullable();
                }
            });
        }

        $this->recordCensus($columnsBefore);
    }

    public function down(): void
    {
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table): void {
                if (Schema::hasColumn('documents', 'fiscal_authority_status')) {
                    $table->dropColumn('fiscal_authority_status');
                }
            });
        }

        if (Schema::hasTable('country_document_settings')) {
            Schema::table('country_document_settings', function (Blueprint $table): void {
                foreach (['fiscal_authority_mode', 'fiscal_authority_types', 'policy_expertise_status'] as $column) {
                    if (Schema::hasColumn('country_document_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    /**
     * FAIL LOUDLY rather than half-apply (gate r1 F-4).
     *
     * The original shape guarded each table with `Schema::hasTable` and moved on. A
     * tenant whose `2026_08_10_120000_create_country_document_settings_table` never
     * ran — a restored snapshot, an errored earlier batch, or a database left behind
     * at an older migration (7 of 12 local tenant databases were in exactly that
     * state at authoring time, gate ruling R-2) — would then have taken ONE of the
     * four columns, recorded this migration as RUN, and never retried. "The migration
     * ran" would stop being evidence that the schema is there, and C-QR0b's seeder
     * would meet a table it believes exists.
     *
     * A migration that cannot do its whole job must not claim it did. Throwing leaves
     * the row out of the `migrations` table, so the rolling command reports the tenant
     * and the operator lands the prerequisite and re-runs.
     *
     * @throws RuntimeException when either table this migration extends is absent
     */
    private function assertPrerequisiteTables(): void
    {
        $missing = array_values(array_filter(
            ['country_document_settings', 'documents'],
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'C-QR0a cannot apply on this tenant: missing table(s) %s. This migration extends both '
            .'`country_document_settings` (created by 2026_08_10_120000_create_country_document_settings_table) '
            .'and `documents`; applying it partially would record it as run with only some of its four '
            .'columns present. Land the prerequisite migration on this tenant and re-run. Verify with the '
            .'per-tenant census: SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '
            ."current_schema() AND (table_name, column_name) IN (('country_document_settings','fiscal_authority_mode'),"
            ."('country_document_settings','fiscal_authority_types'),('country_document_settings','policy_expertise_status'),"
            ."('documents','fiscal_authority_status'))) AS new_columns_present; -- must be 4 after a successful run.",
            implode(', ', $missing),
        ));
    }

    /**
     * How many of this migration's four columns already exist. PostgreSQL only —
     * SQLite is the test driver, never a tenant database, and the `information_schema`
     * half has no SQLite equivalent. Returns null off PostgreSQL so the census can say
     * "not measured" instead of "zero".
     */
    private function countNewColumns(): ?int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }

        $row = DB::selectOne(
            "SELECT COUNT(*) AS present
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND (table_name, column_name) IN (
                      ('country_document_settings','fiscal_authority_mode'),
                      ('country_document_settings','fiscal_authority_types'),
                      ('country_document_settings','policy_expertise_status'),
                      ('documents','fiscal_authority_status'))"
        );

        return $row === null ? null : (int) $row->present;
    }

    /**
     * The census the docblock documents, executed so the per-tenant numbers land in
     * the migration output instead of having to be reconstructed afterwards.
     *
     * `$columnsBefore` is measured BEFORE the columns are added (gate r1 F-8): the
     * census used to run only afterwards, so `new_columns_present` was always 4 and
     * the emitted record could never distinguish a first application (0 -> 4) from a
     * re-run of an already-migrated tenant (4 -> 4). That distinction is the whole
     * idempotence signal.
     *
     * PostgreSQL only. Purely informational: it cannot fail this migration — the one
     * condition that MUST fail it is handled by `assertPrerequisiteTables()` before
     * any DDL runs.
     */
    private function recordCensus(?int $columnsBefore): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Rule 9: the census names document states through the enums, never through
        // literals that can drift out of step with the PHP cases.
        $posted = DocumentStatus::Posted->value;
        $cancelled = DocumentStatus::Cancelled->value;
        $invoice = DocumentType::Invoice->value;
        $creditNote = DocumentType::CreditNote->value;

        $census = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM country_document_settings) AS settings_rows,
                (SELECT COUNT(*) FROM documents) AS document_rows,
                (SELECT COUNT(*) FROM documents WHERE status = ?) AS posted_documents,
                (SELECT COUNT(*) FROM documents
                  WHERE type IN (?, ?) AND status <> ?) AS authority_scope_documents',
            [$posted, $invoice, $creditNote, $cancelled],
        );

        if ($census === null) {
            return;
        }

        Log::info('C-QR0a fiscal-authority schema census', [
            'settings_rows' => (int) $census->settings_rows,
            'document_rows' => (int) $census->document_rows,
            'posted_documents' => (int) $census->posted_documents,
            'authority_scope_documents' => (int) $census->authority_scope_documents,
            'new_columns_before' => $columnsBefore,
            'new_columns_after' => $this->countNewColumns(),
        ]);
    }
};
