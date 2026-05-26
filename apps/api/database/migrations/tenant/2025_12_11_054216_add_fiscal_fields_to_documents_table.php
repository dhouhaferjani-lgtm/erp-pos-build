<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Add fiscal_category column
            $table->string('fiscal_category', 20)
                ->default('NON_FISCAL')
                ->after('type');

            // Add fiscal_status column (separate from operational status)
            $table->string('fiscal_status', 20)
                ->default('DRAFT')
                ->after('fiscal_category');

            // Add indexes for common queries
            $table->index('fiscal_status');
            $table->index(['fiscal_category', 'fiscal_status']);
        });

        // Backfill fiscal_category based on document type
        DB::statement("
            UPDATE documents
            SET fiscal_category = CASE
                WHEN type = 'invoice' THEN 'TAX_INVOICE'
                WHEN type = 'credit_note' THEN 'CREDIT_NOTE'
                ELSE 'NON_FISCAL'
            END
        ");

        // Backfill fiscal_status based on operational status
        // Posted/Paid/Received documents are considered SEALED (fiscally immutable)
        // Cancelled documents are VOIDED
        // Draft/Confirmed remain as DRAFT
        DB::statement("
            UPDATE documents
            SET fiscal_status = CASE
                WHEN status IN ('posted', 'paid', 'received') THEN 'SEALED'
                WHEN status = 'cancelled' THEN 'VOIDED'
                ELSE 'DRAFT'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['documents_fiscal_status_index']);
            $table->dropIndex(['documents_fiscal_category_fiscal_status_index']);
            $table->dropColumn(['fiscal_category', 'fiscal_status']);
        });
    }
};
