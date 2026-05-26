<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite_item_id to pos_receipt_lines for F&B menu item support.
     *
     * Enables polymorphic receipt lines: each line references either a product_id
     * OR a composite_item_id (XOR constraint). The existing modifiers JSONB column
     * is already present for modifier snapshot storage.
     */
    public function up(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->foreignUuid('composite_item_id')
                ->nullable()
                ->after('product_id')
                ->constrained('composite_items')
                ->restrictOnDelete();

            $table->index('composite_item_id');
        });

        // Make product_id nullable (was required before) and add XOR constraint
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop the old line_total_calc constraint that doesn't account for modifier adjustments
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_line_total_calc');

            // XOR: exactly one of product_id or composite_item_id must be set
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_sellable_xor CHECK (
                (product_id IS NOT NULL AND composite_item_id IS NULL) OR
                (product_id IS NULL AND composite_item_id IS NOT NULL)
            )');
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_sellable_xor');
        }

        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->dropForeign(['composite_item_id']);
            $table->dropIndex(['composite_item_id']);
            $table->dropColumn('composite_item_id');
        });
    }
};
