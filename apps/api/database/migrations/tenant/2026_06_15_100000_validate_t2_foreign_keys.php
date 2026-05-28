<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 11c — Promote every NOT VALID FK and CHECK constraint from the T2
 * (Product Variants) migration batch to fully validated state.
 *
 * Background:
 *   Tasks 5–10 added 11 foreign keys and 4 CHECK constraints with NOT VALID so
 *   that the high-cardinality ALTER TABLE operations completed without a full-table
 *   scan at migration time (safe for production tables with existing rows).
 *
 *   VALIDATE CONSTRAINT performs the deferred scan.  It holds a ShareUpdateExclusiveLock
 *   (does NOT block reads/writes) and can run after the initial deploy when the
 *   table is quiescent.  Running it as a migration ensures it executes during the
 *   next deploy's migrate phase, keeping the schema in a fully consistent state.
 *
 * `$withinTransaction = false`:
 *   VALIDATE CONSTRAINT cannot run inside an open transaction block on PostgreSQL.
 *
 * `down()` is intentionally a no-op: a validated constraint cannot be reverted to
 * NOT VALID — the semantic change (data is now verified) is irreversible.  The
 * parent T2 migration down() methods still drop the constraints entirely if a
 * full rollback is required.
 *
 * NOTE: channel_product_mappings.variant_id intentionally omitted — it is a plain
 * UUID column with NO FK constraint (Task 9b only added partial unique indexes).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // 11 FK constraints added NOT VALID in Tasks 5–10.
        // 4 CHECK constraints added NOT VALID in Tasks 8 (document_lines) and 10
        // (pos_receipt_lines, pos_order_lines, recipe_lines).
        //
        // Several tables appear in both lists (document_lines, pos_receipt_lines,
        // pos_order_lines, recipe_lines), so a list-of-pairs is used instead of
        // an associative array to avoid duplicate-key collisions.
        $constraints = [
            // ── Foreign keys ────────────────────────────────────────────────────
            ['stock_levels',                          'stock_levels_variant_id_foreign'],
            ['stock_movements',                       'stock_movements_variant_id_foreign'],
            ['stock_reservations',                    'stock_reservations_variant_id_foreign'],
            ['product_batches',                       'product_batches_variant_id_foreign'],
            ['document_lines',                        'document_lines_variant_id_foreign'],
            ['pos_receipt_lines',                     'pos_receipt_lines_variant_id_foreign'],
            ['pos_order_lines',                       'pos_order_lines_variant_id_foreign'],
            ['pos_receipt_line_batch_allocations',    'pos_receipt_line_batch_allocations_variant_id_foreign'],
            ['catalog_cart_items',                    'catalog_cart_items_variant_id_foreign'],
            ['price_list_items',                      'price_list_items_variant_id_foreign'],
            ['recipe_lines',                          'recipe_lines_component_variant_id_foreign'],
            // ── CHECK constraints ───────────────────────────────────────────────
            ['document_lines',                        'document_lines_variant_requires_product'],
            ['pos_receipt_lines',                     'pos_receipt_lines_variant_requires_product'],
            ['pos_order_lines',                       'pos_order_lines_variant_requires_product'],
            ['recipe_lines',                          'recipe_lines_variant_requires_product'],
        ];

        foreach ($constraints as [$table, $constraint]) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
        }
    }

    public function down(): void
    {
        // No-op: a validated constraint cannot be reverted to NOT VALID.
        // Full T2 rollback is handled by the individual Tasks 5–10 down() methods,
        // which DROP the constraints entirely.
    }
};
