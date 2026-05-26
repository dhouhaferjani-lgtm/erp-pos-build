<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-B Milestone 1: Update journal entries hash chain for fiscal compliance.
 *
 * This migration:
 * 1. Renames 'hash' to 'fiscal_hash' for consistency with documents table
 * 2. Updates hash column sizes from 255 to 64 chars (SHA-256 is exactly 64 chars)
 * 3. Adds unique constraint on fiscal_hash for integrity
 * 4. Adds company_id + chain_sequence index (GL chains are per-company)
 * 5. Adds fiscal_hash index for fast hash lookups
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Step 1: Rename 'hash' to 'fiscal_hash' for consistency
            $table->renameColumn('hash', 'fiscal_hash');
        });

        // Need separate schema call after rename to modify the column
        Schema::table('journal_entries', function (Blueprint $table) {
            // Step 2: Update column sizes to 64 chars (SHA-256 length)
            $table->string('fiscal_hash', 64)->nullable()->change();
            $table->string('previous_hash', 64)->nullable()->change();

            // Step 3: Add unique constraint on fiscal_hash
            // This ensures each hash is unique across all journal entries
            $table->unique('fiscal_hash', 'idx_gl_fiscal_hash_unique');

            // Step 4: Add company_id + chain_sequence index
            // GL chains are per-company (each company has independent books)
            $table->index(['company_id', 'chain_sequence'], 'idx_gl_company_chain');

            // Step 5: Add fiscal_hash index for fast hash lookups
            $table->index('fiscal_hash', 'idx_gl_fiscal_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_gl_fiscal_hash');
            $table->dropIndex('idx_gl_company_chain');
            $table->dropUnique('idx_gl_fiscal_hash_unique');
        });

        // Revert column changes
        Schema::table('journal_entries', function (Blueprint $table) {
            // Revert column sizes back to 255
            $table->string('fiscal_hash', 255)->nullable()->change();
            $table->string('previous_hash', 255)->nullable()->change();

            // Rename back to 'hash'
            $table->renameColumn('fiscal_hash', 'hash');
        });
    }
};
