<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Counting module - Counter Metrics table.
 *
 * Stores aggregated performance metrics for users who perform inventory counts.
 * Used for accuracy tracking, speed analysis, and counter reliability scoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counter_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');

            // Counts performed
            $table->unsignedInteger('total_counts')->default(0);
            $table->unsignedInteger('total_items_counted')->default(0);

            // Accuracy metrics
            $table->unsignedInteger('matches_with_theoretical')->default(0);
            $table->unsignedInteger('matches_with_other_counter')->default(0);
            $table->unsignedInteger('disagreements_proven_wrong')->default(0);
            $table->unsignedInteger('disagreements_proven_right')->default(0);

            // Speed metrics
            $table->decimal('avg_seconds_per_item', 10, 2)->nullable();

            // Period - metrics are aggregated by period
            $table->date('period_start');
            $table->date('period_end');

            $table->timestampsTz();

            // Foreign keys
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            // Unique per user per period
            $table->unique(['user_id', 'period_start', 'period_end']);
            $table->index(['company_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counter_metrics');
    }
};
