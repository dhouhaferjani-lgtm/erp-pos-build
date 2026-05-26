<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_mandatory_core');

        // Allow DRAFT fiscal documents to have NULL fiscal_hash and chain_sequence.
        // These fields are populated only when the document is SEALED via DocumentPostingService.
        DB::statement("
            ALTER TABLE documents
            ADD CONSTRAINT chk_fiscal_mandatory_core
            CHECK (
                fiscal_category = 'NON_FISCAL'
                OR fiscal_status = 'DRAFT'
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
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_fiscal_mandatory_core');

        // Restore the original strict constraint
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
    }
};
