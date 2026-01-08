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
     * Add location_id to payment_allocations for location-based treasury reporting.
     * This enables:
     * - Cash flow analysis per location
     * - Payment reconciliation by location
     * - Location-specific A/R and A/P aging
     */
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            // Add location_id as nullable (inherits from payment or document)
            $table->uuid('location_id')
                ->nullable()
                ->after('payment_id');

            // Foreign key to locations table
            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->nullOnDelete();

            // Index for location-based treasury queries
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
