<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies that Task 11c promotes every T2 NOT VALID FK and CHECK constraint
 * to fully validated state.
 *
 * Strategy — RefreshDatabase is safe here:
 *   The validation migration (2026_06_15_100000) uses only VALIDATE CONSTRAINT,
 *   which is compatible with a transaction context on PostgreSQL.  RefreshDatabase
 *   triggers `php artisan migrate`, which runs the validation migration as part of
 *   the normal migrate sequence, so by the time the test body executes the
 *   constraints are already validated.  We simply query pg_constraint to assert
 *   convalidated = true for a representative sample.
 *
 * PostgreSQL-only: skipped automatically on SQLite (no pg_constraint catalog).
 */
class T2ForeignKeyValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'T2ForeignKeyValidationTest requires PostgreSQL (pg_constraint catalog).'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All 15 T2 constraints (11 FKs + 4 CHECKs) must be fully validated
     * (convalidated = true in pg_constraint).
     */
    public function test_t2_fks_are_validated(): void
    {
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

        foreach ($constraints as [$table, $constraintName]) {
            $row = DB::selectOne(
                'SELECT c.convalidated
                 FROM pg_constraint c
                 JOIN pg_class t ON t.oid = c.conrelid
                 WHERE t.relname = ? AND c.conname = ?',
                [$table, $constraintName]
            );

            $this->assertNotNull(
                $row,
                "Constraint {$constraintName} on {$table} not found in pg_constraint — "
                .'did the T2 migration batch run?'
            );

            $this->assertTrue(
                (bool) $row->convalidated,
                "Constraint {$constraintName} on {$table} is NOT YET VALIDATED "
                .'(convalidated = false) — Task 11c validation migration may not have run.'
            );
        }
    }

    /**
     * channel_product_mappings.variant_id must have NO FK constraint pointing at
     * product_variants — it is intentionally a plain UUID column (Task 9b only
     * added partial unique indexes).
     */
    public function test_channel_product_mappings_has_no_variant_fk(): void
    {
        $row = DB::selectOne(
            "SELECT c.conname
             FROM pg_constraint c
             JOIN pg_class t  ON t.oid  = c.conrelid
             JOIN pg_class t2 ON t2.oid = c.confrelid
             WHERE t.relname  = 'channel_product_mappings'
               AND t2.relname = 'product_variants'
               AND c.contype  = 'f'",
            []
        );

        $this->assertNull(
            $row,
            'channel_product_mappings should have NO FK to product_variants '
            .'— found: '.($row?->conname ?? 'none')
        );
    }
}
