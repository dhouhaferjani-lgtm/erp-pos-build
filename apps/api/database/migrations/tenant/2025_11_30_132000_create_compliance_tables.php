<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.8: Create compliance tables.
 *
 * Compliance tables enable:
 * - company_hash_chains: Immutable hash chains for fiscal documents
 * - company_sequences: Gap-free document numbering
 * - fiscal_years: Fiscal year definitions
 * - fiscal_periods: Monthly/quarterly periods for accounting
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Company hash chains - for fiscal document integrity
        Schema::create('company_hash_chains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');

            // Chain identification
            $table->string('chain_type', 30); // invoice, credit_note, receipt, payment, etc.
            $table->unsignedBigInteger('sequence_number');

            // Hash data
            $table->string('hash', 64); // SHA-256
            $table->string('previous_hash', 64)->nullable(); // null for first in chain
            $table->string('payload_hash', 64)->nullable(); // hash of document content

            // Document reference
            $table->uuid('document_id')->nullable();
            $table->string('document_type', 50)->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            // Indexes for chain integrity verification
            $table->unique(['company_id', 'chain_type', 'sequence_number'], 'idx_hash_chain_unique_seq');
            $table->index(['company_id', 'chain_type'], 'idx_hash_chain_type');
            $table->index('document_id', 'idx_hash_chain_document');
        });

        // Company sequences - for document numbering
        Schema::create('company_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');

            // Sequence identification
            $table->string('sequence_type', 30); // invoice, quote, sales_order, etc.
            $table->string('prefix', 20);
            $table->unsignedBigInteger('current_number')->default(0);

            // Format options
            $table->string('format', 100)->nullable(); // e.g., {prefix}-{year}-{number:05d}
            $table->boolean('reset_yearly')->default(false);
            $table->unsignedSmallInteger('last_reset_year')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            // Each company can have only one sequence per type
            $table->unique(['company_id', 'sequence_type'], 'idx_sequence_unique_type');
        });

        // Fiscal years
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');

            // Year identification
            $table->string('name', 50); // e.g., "2025", "FY2024-2025"
            $table->date('start_date');
            $table->date('end_date');

            // Status
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['company_id', 'is_closed'], 'idx_fiscal_years_company_status');
            $table->index(['company_id', 'start_date'], 'idx_fiscal_years_company_date');
        });

        // Fiscal periods (monthly or quarterly)
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('fiscal_year_id');
            $table->uuid('company_id'); // Denormalized for easier queries

            // Period identification
            $table->string('name', 50); // e.g., "January 2025", "Q1 2025"
            $table->unsignedTinyInteger('period_number'); // 1-12 for months, 1-4 for quarters
            $table->date('start_date');
            $table->date('end_date');

            // Status
            $table->string('status', 20)->default('open'); // open, closed, locked
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['company_id', 'status'], 'idx_fiscal_periods_company_status');
            $table->index(['company_id', 'start_date'], 'idx_fiscal_periods_company_date');
            $table->unique(['fiscal_year_id', 'period_number'], 'idx_fiscal_periods_unique_num');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('company_sequences');
        Schema::dropIfExists('company_hash_chains');
    }
};
