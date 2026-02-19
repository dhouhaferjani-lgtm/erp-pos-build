<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PostgreSQL-specific constraint modification
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop the old constraint
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_category_enum');

            // Add the updated constraint with DELIVERY_NOTE and RETURN_NOTE
            DB::statement("
                ALTER TABLE documents
                ADD CONSTRAINT chk_fiscal_category_enum
                CHECK (fiscal_category IN ('NON_FISCAL', 'FISCAL_RECEIPT', 'TAX_INVOICE', 'CREDIT_NOTE', 'DELIVERY_NOTE', 'RETURN_NOTE'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // PostgreSQL-specific constraint modification
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop the constraint
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_category_enum');

            // Restore the old constraint (without DELIVERY_NOTE and RETURN_NOTE)
            DB::statement("
                ALTER TABLE documents
                ADD CONSTRAINT chk_fiscal_category_enum
                CHECK (fiscal_category IN ('NON_FISCAL', 'FISCAL_RECEIPT', 'TAX_INVOICE', 'CREDIT_NOTE'))
            ");
        }
    }
};
