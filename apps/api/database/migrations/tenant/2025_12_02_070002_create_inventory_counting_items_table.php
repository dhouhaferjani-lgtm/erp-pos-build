<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory Counting module - Items table.
 *
 * Stores individual items being counted in each counting session.
 * Each item tracks theoretical vs actual counts from up to 3 counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counting_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('counting_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable(); // For future product variants support
            $table->uuid('location_id');

            // Theoretical quantity (frozen at counting creation)
            $table->decimal('theoretical_qty', 15, 4);

            // Count results - Count 1
            $table->decimal('count_1_qty', 15, 4)->nullable();
            $table->timestampTz('count_1_at')->nullable();
            $table->text('count_1_notes')->nullable();

            // Count results - Count 2
            $table->decimal('count_2_qty', 15, 4)->nullable();
            $table->timestampTz('count_2_at')->nullable();
            $table->text('count_2_notes')->nullable();

            // Count results - Count 3
            $table->decimal('count_3_qty', 15, 4)->nullable();
            $table->timestampTz('count_3_at')->nullable();
            $table->text('count_3_notes')->nullable();

            // Resolution
            $table->decimal('final_qty', 15, 4)->nullable();
            $table->string('resolution_method', 30)->default('pending');
            // Allowed values: pending, auto_all_match, auto_counters_agree,
            // third_count_decisive, manual_override
            $table->text('resolution_notes')->nullable();
            $table->uuid('resolved_by_user_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            // Flags
            $table->boolean('is_flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->boolean('is_unexpected_item')->default(false);

            $table->timestampsTz();

            // Foreign keys
            $table->foreign('counting_id')
                ->references('id')
                ->on('inventory_countings')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');

            $table->foreign('location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('restrict');

            $table->foreign('resolved_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Indexes
            $table->index(['counting_id', 'is_flagged']);
            $table->index(['counting_id', 'resolution_method']);
            $table->index(['product_id', 'location_id']);
        });

        // PostgreSQL specific: partial unique indexes (SQLite doesn't support partial indexes)
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // Unique constraint for product+variant+location within a counting
            // Using a partial index to handle nullable variant_id
            DB::statement('
                CREATE UNIQUE INDEX idx_counting_item_unique
                ON inventory_counting_items (counting_id, product_id, location_id)
                WHERE variant_id IS NULL
            ');

            DB::statement('
                CREATE UNIQUE INDEX idx_counting_item_variant_unique
                ON inventory_counting_items (counting_id, product_id, variant_id, location_id)
                WHERE variant_id IS NOT NULL
            ');

            // Add CHECK constraint for resolution_method
            DB::statement("
                ALTER TABLE inventory_counting_items
                ADD CONSTRAINT chk_valid_resolution_method
                CHECK (resolution_method IN (
                    'pending', 'auto_all_match', 'auto_counters_agree',
                    'third_count_decisive', 'manual_override'
                ))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counting_items');
    }
};
