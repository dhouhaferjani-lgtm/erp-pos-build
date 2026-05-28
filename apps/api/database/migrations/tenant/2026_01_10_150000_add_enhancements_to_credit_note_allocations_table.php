<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance and audit enhancements to credit_note_allocations table.
 *
 * Enhancements:
 * 1. Composite index (invoice_id, created_at) for faster credit history queries
 * 2. allocated_by field for audit trail (who posted the credit note)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('credit_note_allocations', function (Blueprint $table) {
            // Add audit field to track who allocated the credit note (if not exists)
            if (! Schema::hasColumn('credit_note_allocations', 'allocated_by')) {
                $table->foreignUuid('allocated_by')
                    ->nullable()
                    ->after('amount')
                    ->constrained('users')
                    ->onDelete('set null');
            }

            // Add composite index for faster credit allocation history queries
            // Common query: "Get all credit allocations for invoice X ordered by date"
            if (! Schema::hasIndex('credit_note_allocations', 'credit_note_allocations_invoice_date_index')) {
                $table->index(['invoice_id', 'created_at'], 'credit_note_allocations_invoice_date_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_note_allocations', function (Blueprint $table) {
            // Drop composite index
            $table->dropIndex('credit_note_allocations_invoice_date_index');

            // Drop foreign key and column
            $table->dropForeign(['allocated_by']);
            $table->dropColumn('allocated_by');
        });
    }
};
