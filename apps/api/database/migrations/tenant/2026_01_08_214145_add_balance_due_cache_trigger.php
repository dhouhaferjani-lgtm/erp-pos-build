<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add PostgreSQL trigger to automatically maintain balance_due cache.
 *
 * The balance_due column is a CACHED value for performance optimization.
 * The SOURCE OF TRUTH is getOutstandingAmount() method which computes from allocations.
 *
 * This trigger automatically updates the cache whenever payment allocations change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only create triggers for PostgreSQL
        // SQLite (used in tests) doesn't support PostgreSQL trigger syntax
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Create the trigger function
        DB::unprepared("
            CREATE OR REPLACE FUNCTION update_document_balance_due()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Update the document's cached balance_due
                -- Formula: Total - SUM(payment allocations) - SUM(credit note allocations)
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
                WHERE id = COALESCE(NEW.document_id, OLD.document_id);

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        ");

        // Create trigger for payment_allocations table
        DB::unprepared('
            CREATE TRIGGER payment_allocation_balance_update
            AFTER INSERT OR UPDATE OR DELETE ON payment_allocations
            FOR EACH ROW
            EXECUTE FUNCTION update_document_balance_due();
        ');

        // Note: Trigger for credit_note_allocations will be added in Phase 2
        // when that table is created

        // Add index on payment_allocations.document_id for performance
        DB::statement('CREATE INDEX IF NOT EXISTS payment_allocations_document_id_index ON payment_allocations(document_id)');

        // Add index on balance_due for list queries and filters
        DB::statement('CREATE INDEX IF NOT EXISTS documents_balance_due_index ON documents(balance_due) WHERE type = \'invoice\' AND status = \'posted\'');
    }

    public function down(): void
    {
        // Only drop triggers for PostgreSQL
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Drop the trigger
        DB::unprepared('DROP TRIGGER IF EXISTS payment_allocation_balance_update ON payment_allocations');

        // Drop the function
        DB::unprepared('DROP FUNCTION IF EXISTS update_document_balance_due()');

        // Drop indexes
        DB::statement('DROP INDEX IF EXISTS payment_allocations_document_id_index');
        DB::statement('DROP INDEX IF EXISTS documents_balance_due_index');
    }
};
