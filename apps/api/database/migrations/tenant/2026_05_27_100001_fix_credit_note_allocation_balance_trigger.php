<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix the credit_note_allocations balance-due trigger.
 *
 * The shared update_document_balance_due() function (2026_01_08_214145) targets
 * the affected document via `COALESCE(NEW.document_id, OLD.document_id)`. That is
 * correct for payment_allocations (which has a document_id column) but the
 * credit_note_allocations table has NO document_id — its columns are invoice_id
 * and credit_note_id. The Phase-2 migration (2026_01_10_100001) attached the
 * SAME shared function to credit_note_allocations, so on PostgreSQL every
 * INSERT/UPDATE/DELETE on credit_note_allocations raises
 * `record "new" has no field "document_id"` (SQLSTATE 42703) — credit-note
 * allocation is completely broken under the production truth engine. SQLite does
 * not execute the trigger, which is why the defect was invisible there.
 *
 * This installs a dedicated trigger function that recomputes the affected
 * invoice's cached balance_due using invoice_id, and re-points the
 * credit_note_allocation_balance_update trigger at it. The balance formula
 * matches the shared function (total - payment allocations - credit allocations).
 *
 * No-op on SQLite (no triggers there).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION update_invoice_balance_due_from_credit_note()
            RETURNS TRIGGER AS $$
            BEGIN
                UPDATE documents
                SET balance_due = (
                    COALESCE(total, 0) -
                    COALESCE((
                        SELECT SUM(amount)
                        FROM payment_allocations
                        WHERE document_id = documents.id
                    ), 0) -
                    COALESCE((
                        SELECT SUM(amount)
                        FROM credit_note_allocations
                        WHERE invoice_id = documents.id
                    ), 0)
                ),
                updated_at = NOW()
                WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // Re-point the existing trigger at the corrected function.
        DB::unprepared('DROP TRIGGER IF EXISTS credit_note_allocation_balance_update ON credit_note_allocations');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER credit_note_allocation_balance_update
            AFTER INSERT OR UPDATE OR DELETE ON credit_note_allocations
            FOR EACH ROW
            EXECUTE FUNCTION update_invoice_balance_due_from_credit_note();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Restore the prior (broken) wiring to the shared function.
        DB::unprepared('DROP TRIGGER IF EXISTS credit_note_allocation_balance_update ON credit_note_allocations');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER credit_note_allocation_balance_update
            AFTER INSERT OR UPDATE OR DELETE ON credit_note_allocations
            FOR EACH ROW
            EXECUTE FUNCTION update_document_balance_due();
        SQL);
        DB::unprepared('DROP FUNCTION IF EXISTS update_invoice_balance_due_from_credit_note()');
    }
};
