<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Counting module - Assignments table.
 *
 * Tracks individual counter assignments for each counting session.
 * Each counting can have up to 3 counters (count 1, 2, 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counting_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('counting_id');
            $table->uuid('user_id');
            $table->unsignedTinyInteger('count_number'); // 1, 2, or 3

            $table->string('status', 20)->default('pending');
            // Allowed values: pending, in_progress, completed, overdue

            $table->timestampTz('assigned_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('deadline')->nullable();

            // Progress tracking
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('counted_items')->default(0);

            $table->timestampsTz();

            // Foreign keys
            $table->foreign('counting_id')
                ->references('id')
                ->on('inventory_countings')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            // Unique constraint - one assignment per count number per counting
            $table->unique(['counting_id', 'count_number']);
            $table->index(['user_id', 'status']);
        });

        // Add CHECK constraints (PostgreSQL only - SQLite doesn't support ALTER TABLE ADD CONSTRAINT)
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('
                ALTER TABLE inventory_counting_assignments
                ADD CONSTRAINT chk_valid_count_number
                CHECK (count_number BETWEEN 1 AND 3)
            ');

            DB::statement("
                ALTER TABLE inventory_counting_assignments
                ADD CONSTRAINT chk_valid_assignment_status
                CHECK (status IN ('pending', 'in_progress', 'completed', 'overdue'))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counting_assignments');
    }
};
