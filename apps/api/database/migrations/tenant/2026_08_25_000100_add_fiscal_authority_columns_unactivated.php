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
 *    + `fiscal_authority_types`    json         NULL   ({@see FiscalAuthorityTypes})
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
 * constraint any existing row could violate, hence NO abort path: the pre-flight
 * census below is INFORMATIONAL — it sizes what C-QR0b will have to backfill — and
 * this migration never throws on its result.
 *
 * PRE-FLIGHT CENSUS (read-only; run per tenant database before promoting).
 * Docblock-census pattern copied from
 * `2026_08_11_000100_unique_journal_entries_source_inventory_movement.php`, with
 * the abort deliberately dropped for the reason above.
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
 * check. `authority_scope_documents` is the C-QR0b initialiser's population and the
 * OQ-49 quarantine's upper bound; it does not gate this migration.
 *
 * Fleet-abort risk: NONE. Unattended-safe, idempotent, guarded per object, and
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
        if (Schema::hasTable('country_document_settings')) {
            Schema::table('country_document_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('country_document_settings', 'fiscal_authority_mode')) {
                    $table->string('fiscal_authority_mode', self::POLICY_COLUMN_LENGTH)->nullable();
                }

                if (! Schema::hasColumn('country_document_settings', 'fiscal_authority_types')) {
                    $table->json('fiscal_authority_types')->nullable();
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

        $this->recordCensus();
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
     * The census the docblock documents, executed so the per-tenant numbers land in
     * the migration output instead of having to be reconstructed afterwards.
     *
     * PostgreSQL only — SQLite is the test driver, never a tenant database, and the
     * `information_schema` half has no SQLite equivalent. Purely informational: it
     * cannot fail this migration.
     */
    private function recordCensus(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('documents') || ! Schema::hasTable('country_document_settings')) {
            return;
        }

        // Rule 9: the census names document states through the enums, never through
        // literals that can drift out of step with the PHP cases.
        $posted = DocumentStatus::Posted->value;
        $cancelled = DocumentStatus::Cancelled->value;
        $invoice = DocumentType::Invoice->value;
        $creditNote = DocumentType::CreditNote->value;

        $census = DB::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM country_document_settings) AS settings_rows,
                (SELECT COUNT(*) FROM documents) AS document_rows,
                (SELECT COUNT(*) FROM documents WHERE status = ?) AS posted_documents,
                (SELECT COUNT(*) FROM documents
                  WHERE type IN (?, ?) AND status <> ?) AS authority_scope_documents,
                (SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = current_schema()
                    AND (table_name, column_name) IN (
                          ('country_document_settings','fiscal_authority_mode'),
                          ('country_document_settings','fiscal_authority_types'),
                          ('country_document_settings','policy_expertise_status'),
                          ('documents','fiscal_authority_status'))) AS new_columns_present",
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
            'new_columns_present' => (int) $census->new_columns_present,
        ]);
    }
};
