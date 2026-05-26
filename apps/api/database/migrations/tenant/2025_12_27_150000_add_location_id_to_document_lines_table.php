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
     * Add location_id to document_lines for tracking which location each line ships from/receives to.
     * This enables:
     * - Multi-location sales orders (line 1 from warehouse A, line 2 from warehouse B)
     * - Return note location tracking (receive returns at specific location)
     * - Location-based stock movement tracking
     */
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            // Add location_id as nullable (inherits from document if not specified)
            $table->uuid('location_id')
                ->nullable()
                ->after('document_id');

            // Foreign key to locations table (nullOnDelete preserves history if location removed)
            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->nullOnDelete();

            // Index for location-based queries (e.g., sales by location, stock movements by location)
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
