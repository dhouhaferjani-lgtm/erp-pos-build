<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only apply CHECK constraints on PostgreSQL
        // SQLite doesn't support ALTER TABLE ADD CONSTRAINT
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Drop constraints if they exist (for idempotency)
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_mandatory_core');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_category_enum');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_status_enum');

        // Add CHECK constraint for fiscal documents mandatory fields
        // Only enforced when fiscal_category is NOT 'NON_FISCAL'
        // Note: previous_hash CAN be NULL for the genesis document (first in chain)
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_fiscal_mandatory_core
            CHECK (
                fiscal_category = 'NON_FISCAL'
                OR (
                    document_date IS NOT NULL
                    AND document_number IS NOT NULL
                    AND total IS NOT NULL
                    AND currency IS NOT NULL
                    AND fiscal_hash IS NOT NULL
                    AND chain_sequence IS NOT NULL
                )
            )
        ");

        // Add CHECK constraint for fiscal_category enum values
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_fiscal_category_enum
            CHECK (fiscal_category IN ('NON_FISCAL', 'FISCAL_RECEIPT', 'TAX_INVOICE', 'CREDIT_NOTE'))
        ");

        // Add CHECK constraint for fiscal_status enum values
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_fiscal_status_enum
            CHECK (fiscal_status IN ('DRAFT', 'SEALED', 'VOIDED'))
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_mandatory_core');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_category_enum');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_status_enum');
    }
};
