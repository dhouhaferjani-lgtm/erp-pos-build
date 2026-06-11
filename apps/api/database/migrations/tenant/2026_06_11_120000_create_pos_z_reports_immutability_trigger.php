<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M1 (2026-06-09 Z-report audit) — pos_z_reports append-only trigger.
     *
     * pos_z_reports is the stored/exported mirror of the fiscal Z closure
     * (NF525). Unlike pos_receipts / voucher_ledger / fiscal_events it had no
     * immutability trigger, so a sealed Z row could be silently UPDATEd or
     * DELETEd at the SQL level.
     *
     * The trigger is transition-aware. Writer inventory (verified 2026-06-11):
     *  - ReportGenerationService::generateZReport: builds the model in memory,
     *    sets fiscal_hash, then save() → a single INSERT. Never UPDATEs.
     *  - ZReportSyncController: insert-only (duplicate device push → early
     *    return / 409 HASH_MISMATCH before any write).
     *  - ZReportProjection::apply: INSERT for new rows; the ONE legitimate
     *    UPDATE is the one-time legacy→canonical upgrade — a legacy mirror row
     *    (fiscal_event_id IS NULL) is adopted by the verified canonical
     *    Z_REPORT fiscal event, rewriting the mirror fields (fiscal_hash,
     *    report_data, grand_totals, canonical_bytes, …) and stamping
     *    fiscal_event_id exactly once.
     *
     * Rules enforced:
     *  - DELETE: always blocked.
     *  - id / shift_id / terminal_id: immutable in every transition.
     *  - fiscal_event_id NOT NULL (canonical row): fully sealed — any UPDATE
     *    is rejected (idempotent re-projection early-returns before saving,
     *    so no legitimate writer ever UPDATEs a canonical row).
     *  - fiscal_event_id NULL (legacy row): only the canonical upgrade
     *    (NEW.fiscal_event_id IS NOT NULL) may modify the row.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_z_report_modification() RETURNS trigger AS $$
            BEGIN
                -- Block all DELETE operations (NF525 append-only requirement)
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Cannot delete fiscal Z-report % (NF525 append-only)', OLD.z_number
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    -- Identity columns are immutable in every transition.
                    IF NEW.id IS DISTINCT FROM OLD.id
                       OR NEW.shift_id IS DISTINCT FROM OLD.shift_id
                       OR NEW.terminal_id IS DISTINCT FROM OLD.terminal_id THEN
                        RAISE EXCEPTION 'Z-report % identity (id, shift_id, terminal_id) is immutable', OLD.z_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    -- Canonical row (backed by a verified Z_REPORT fiscal event):
                    -- fully sealed. No legitimate writer UPDATEs it.
                    IF OLD.fiscal_event_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Z-report % is sealed by fiscal event % and cannot be modified', OLD.z_number, OLD.fiscal_event_id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    -- Legacy row: only the one-time canonical projection upgrade
                    -- (fiscal_event_id NULL -> value) may modify it.
                    IF NEW.fiscal_event_id IS NULL THEN
                        RAISE EXCEPTION 'Z-report % is sealed; only the one-time canonical projection upgrade may modify it', OLD.z_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION prevent_z_report_modification() IS 'NF525 Immutability: pos_z_reports is append-only. Allows only the one-time legacy->canonical projection upgrade (fiscal_event_id NULL->value); blocks every other UPDATE and all DELETEs.';

            DROP TRIGGER IF EXISTS enforce_z_report_immutability ON pos_z_reports;
            CREATE TRIGGER enforce_z_report_immutability
                BEFORE UPDATE OR DELETE ON pos_z_reports
                FOR EACH ROW EXECUTE FUNCTION prevent_z_report_modification();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS enforce_z_report_immutability ON pos_z_reports;
            DROP FUNCTION IF EXISTS prevent_z_report_modification();
        SQL);
    }
};
