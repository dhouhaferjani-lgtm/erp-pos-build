<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Counting module - Main countings table.
 *
 * This table stores inventory counting sessions (physical counts)
 * with support for blind counting methodology and multi-counter validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_countings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('created_by_user_id');

            // Scope - what is being counted
            $table->string('scope_type', 30);
            // Allowed values: product_location, product, location, category, full_inventory
            $table->jsonb('scope_filters')->default('{}');

            // Configuration
            $table->string('execution_mode', 20)->default('parallel');
            // Allowed values: parallel, sequential
            $table->string('status', 30)->default('draft');
            // Allowed values: draft, scheduled, count_1_in_progress, count_1_completed,
            // count_2_in_progress, count_2_completed, count_3_in_progress, count_3_completed,
            // pending_review, finalized, cancelled

            // Schedule
            $table->timestampTz('scheduled_start')->nullable();
            $table->timestampTz('scheduled_end')->nullable();

            // Counter assignments
            $table->uuid('count_1_user_id')->nullable();
            $table->uuid('count_2_user_id')->nullable();
            $table->uuid('count_3_user_id')->nullable();
            $table->boolean('requires_count_2')->default(true);
            $table->boolean('requires_count_3')->default(false);

            // Options
            $table->boolean('allow_unexpected_items')->default(false);
            $table->text('instructions')->nullable();

            // Timestamps
            $table->timestampsTz();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Foreign keys
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('count_1_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('count_2_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('count_3_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Indexes
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'scheduled_start']);
            $table->index('count_1_user_id');
            $table->index('count_2_user_id');
            $table->index('count_3_user_id');
        });

        // Add CHECK constraints (PostgreSQL only - SQLite doesn't support ALTER TABLE ADD CONSTRAINT)
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('
                ALTER TABLE inventory_countings
                ADD CONSTRAINT chk_valid_schedule
                CHECK (scheduled_end IS NULL OR scheduled_start IS NULL OR scheduled_end > scheduled_start)
            ');

            DB::statement("
                ALTER TABLE inventory_countings
                ADD CONSTRAINT chk_valid_scope_type
                CHECK (scope_type IN ('product_location', 'product', 'location', 'category', 'full_inventory'))
            ");

            DB::statement("
                ALTER TABLE inventory_countings
                ADD CONSTRAINT chk_valid_execution_mode
                CHECK (execution_mode IN ('parallel', 'sequential'))
            ");

            DB::statement("
                ALTER TABLE inventory_countings
                ADD CONSTRAINT chk_valid_status
                CHECK (status IN (
                    'draft', 'scheduled',
                    'count_1_in_progress', 'count_1_completed',
                    'count_2_in_progress', 'count_2_completed',
                    'count_3_in_progress', 'count_3_completed',
                    'pending_review', 'finalized', 'cancelled'
                ))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_countings');
    }
};
