<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add location_id to journal_entries for location-based financial reporting.
     * This enables:
     * - Location-specific P&L statements
     * - Location-based balance sheets
     * - Inter-location transfer accounting
     * - Multi-location consolidation reporting
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            // Add location_id as nullable (company-level entries have no location)
            $table->uuid('location_id')
                ->nullable()
                ->after('company_id');

            // Foreign key to locations table
            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->nullOnDelete();

            // Multi-column index for location-based reporting queries
            // Optimizes: WHERE company_id = X AND location_id = Y AND date BETWEEN...
            $table->index(['company_id', 'location_id'], 'journal_entries_company_location_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex('journal_entries_company_location_index');
            $table->dropColumn('location_id');
        });
    }
};
