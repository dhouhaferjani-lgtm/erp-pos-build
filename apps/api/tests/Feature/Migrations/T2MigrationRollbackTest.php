<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration safety net for the T2 (Product Variants) migration batch.
 *
 * Acceptance criterion §10.1: rollback removes the T2 schema additions and
 * re-applying restores them.
 *
 * WHY no RefreshDatabase:
 *   Several T2 migrations declare `public $withinTransaction = false;` and use
 *   `CREATE INDEX CONCURRENTLY` / `DROP INDEX CONCURRENTLY`.  PostgreSQL refuses
 *   to run CONCURRENTLY inside an open transaction.  RefreshDatabase wraps each
 *   test in a transaction, which would cause every CONCURRENTLY statement to
 *   fail with "cannot run inside a transaction block".
 *
 * Approach — Option B (direct file instantiation):
 *   1. Assert T2 schema is present (pre-condition: T2 already migrated via a
 *      prior `php artisan migrate` call, which happens at test DB setup time).
 *   2. `require` each of the 16 T2 migration files and call `->down()` in
 *      reverse dependency order (015→001), running outside any transaction.
 *   3. Assert the T2 artifacts are absent.
 *   4. Call `->up()` in forward order (001→015) to restore them.
 *   5. Assert the T2 artifacts are present again.
 *   6. Run `migrate` to re-record rows in the migrations table (so the test DB
 *      is left healthy for subsequent test runs).
 *
 * The test is PostgreSQL-only and is skipped automatically on SQLite.
 */
class T2MigrationRollbackTest extends TestCase
{
    // ── migration file list in dependency order (001 first) ──────────────────
    private const T2_FILES = [
        '2026_06_02_100001_create_product_attributes_table.php',
        '2026_06_02_100002_create_product_attribute_values_table.php',
        '2026_06_02_100003_create_product_variants_table.php',
        '2026_06_02_100004_create_product_variant_attribute_values_table.php',
        '2026_06_02_100005_add_variant_id_to_stock_levels.php',
        '2026_06_02_100006_add_variant_id_to_stock_movements.php',
        '2026_06_02_100007_add_variant_id_to_stock_reservations.php',
        '2026_06_02_100008_add_variant_id_to_product_batches.php',
        '2026_06_02_100009_add_variant_id_to_document_lines.php',
        '2026_06_02_100010_add_variant_id_to_pos_receipt_lines.php',
        '2026_06_02_100011_add_variant_id_to_pos_order_lines.php',
        '2026_06_02_100012_add_variant_id_to_pos_receipt_line_batch_allocations.php',
        '2026_06_02_100013_add_variant_id_to_catalog_cart_items.php',
        '2026_06_02_100013b_fix_channel_product_mappings_partial_unique.php',
        '2026_06_02_100014_add_variant_id_to_price_list_items.php',
        '2026_06_02_100015_add_component_variant_id_to_recipe_lines.php',
    ];

    /** @var array<int, Migration> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'T2MigrationRollbackTest requires PostgreSQL: '
                .'CONCURRENTLY index operations cannot run inside a transaction '
                .'(RefreshDatabase) or on SQLite.'
            );
        }

        // Load all 16 migration instances in forward order.
        $dir = database_path('migrations/tenant');

        foreach (self::T2_FILES as $filename) {
            $path = $dir.'/'.$filename;
            $this->assertFileExists($path, "T2 migration file missing: {$filename}");

            /** @var Migration $instance */
            $instance = require $path;
            $this->migrations[] = $instance;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The core round-trip test:
     *   pre-condition → rollback → assert absent → re-apply → assert present.
     *
     * This is a single test method so the three phases run sequentially with no
     * state reset between them; PHPUnit cannot run CONCURRENTLY DDL in a
     * transaction, and wrapping each phase in a separate test would require
     * either transaction isolation (impossible here) or brittle ordered tests.
     */
    public function test_t2_migrations_roll_back_and_reapply_cleanly(): void
    {
        // ── Phase 0: self-heal — guarantee a fully-migrated baseline ─────────
        // Any schema-mutating predecessor test (e.g. a Channel test that
        // manually drops/recreates tables without all T2 migrations) may leave
        // the DB in a partial state.  Running migrate here is idempotent: it is
        // a no-op when the schema is already current, and recovers when it isn't.
        Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/tenant']);

        $this->assertT2SchemaPresent(
            'Pre-condition failed — run `php artisan migrate --force` before this test'
        );

        // ── Phase 1: roll back all 16 migrations in reverse order ────────────
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }

        // ── Phase 2: assert T2 artifacts are absent ───────────────────────────
        $this->assertT2SchemaAbsent();

        // ── Phase 3: re-apply all 16 migrations in forward order ─────────────
        foreach ($this->migrations as $migration) {
            $migration->up();
        }

        // ── Phase 4: assert T2 artifacts are present again ───────────────────
        $this->assertT2SchemaPresent('Re-apply failed — artifacts not restored');

        // ── Phase 5: re-sync the migrations table so the DB is left healthy ──
        // The down()/up() calls manipulate the physical schema but do NOT touch
        // the `migrations` table.  Run `migrate` idempotently so subsequent test
        // runs see a consistent batch state.
        Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/tenant']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assertion helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function assertT2SchemaPresent(string $context = ''): void
    {
        $prefix = $context !== '' ? "[{$context}] " : '';

        // ── 4 new tables ─────────────────────────────────────────────────────
        $this->assertTrue(
            Schema::hasTable('product_attributes'),
            $prefix.'product_attributes table should exist'
        );
        $this->assertTrue(
            Schema::hasTable('product_attribute_values'),
            $prefix.'product_attribute_values table should exist'
        );
        $this->assertTrue(
            Schema::hasTable('product_variants'),
            $prefix.'product_variants table should exist'
        );
        $this->assertTrue(
            Schema::hasTable('product_variant_attribute_values'),
            $prefix.'product_variant_attribute_values table should exist'
        );

        // ── variant_id columns added to existing tables ───────────────────────
        $variantIdTables = [
            'stock_levels',
            'stock_movements',
            'stock_reservations',
            'product_batches',
            'document_lines',
            'pos_receipt_lines',
            'pos_order_lines',
            'pos_receipt_line_batch_allocations',
            'catalog_cart_items',
            'price_list_items',
        ];

        foreach ($variantIdTables as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'variant_id'),
                $prefix."{$table}.variant_id column should exist"
            );
        }

        // ── recipe_lines.component_variant_id ────────────────────────────────
        $this->assertTrue(
            Schema::hasColumn('recipe_lines', 'component_variant_id'),
            $prefix.'recipe_lines.component_variant_id column should exist'
        );

        // ── T2 partial unique indexes on stock_levels (migration 005) ─────────
        $this->assertIndexExists('stock_levels', 'stock_levels_non_variant', $prefix);
        $this->assertIndexExists('stock_levels', 'stock_levels_with_variant', $prefix);

        // ── channel_product_mappings partial unique indexes (migration 013b) ──
        $this->assertIndexExists('channel_product_mappings', 'channel_product_mappings_non_variant', $prefix);
        $this->assertIndexExists('channel_product_mappings', 'channel_product_mappings_with_variant', $prefix);

        // ── pre-T2 unique constraint on channel_product_mappings should be gone
        $this->assertConstraintAbsent('channel_product_mappings', 'channel_product_variant_unique', $prefix);

        // ── T2 partial unique indexes on price_list_items (migration 014) ─────
        $this->assertIndexExists('price_list_items', 'price_list_items_non_variant', $prefix);
        $this->assertIndexExists('price_list_items', 'price_list_items_with_variant', $prefix);

        // ── pre-T2 unique constraint on price_list_items should be gone ───────
        $this->assertConstraintAbsent('price_list_items', 'price_list_product_qty_unique', $prefix);

        // ── T2 partial unique indexes on product_batches (migration 008) ──────
        $this->assertIndexExists('product_batches', 'product_batches_non_variant', $prefix);
        $this->assertIndexExists('product_batches', 'product_batches_with_variant', $prefix);

        // ── pre-T2 unique constraint on product_batches should be gone ────────
        $this->assertConstraintAbsent('product_batches', 'unique_batch_per_product', $prefix);

        // ── pre-T2 unique constraint on stock_levels should be gone (migration 005) ─
        $this->assertConstraintAbsent('stock_levels', 'stock_levels_tenant_id_product_id_location_id_unique', $prefix);
    }

    private function assertT2SchemaAbsent(): void
    {
        // ── 4 new tables must be dropped ─────────────────────────────────────
        $this->assertFalse(
            Schema::hasTable('product_variant_attribute_values'),
            'product_variant_attribute_values should be dropped after rollback'
        );
        $this->assertFalse(
            Schema::hasTable('product_variants'),
            'product_variants should be dropped after rollback'
        );
        $this->assertFalse(
            Schema::hasTable('product_attribute_values'),
            'product_attribute_values should be dropped after rollback'
        );
        $this->assertFalse(
            Schema::hasTable('product_attributes'),
            'product_attributes should be dropped after rollback'
        );

        // ── variant_id columns must be dropped from existing tables ───────────
        $variantIdTables = [
            'stock_levels',
            'stock_movements',
            'stock_reservations',
            'product_batches',
            'document_lines',
            'pos_receipt_lines',
            'pos_order_lines',
            'pos_receipt_line_batch_allocations',
            'catalog_cart_items',
            'price_list_items',
        ];

        foreach ($variantIdTables as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'variant_id'),
                "{$table}.variant_id should be dropped after rollback"
            );
        }

        // ── recipe_lines.component_variant_id must be dropped ─────────────────
        $this->assertFalse(
            Schema::hasColumn('recipe_lines', 'component_variant_id'),
            'recipe_lines.component_variant_id should be dropped after rollback'
        );

        // ── T2 partial unique indexes on stock_levels must be gone ────────────
        $this->assertIndexAbsent('stock_levels', 'stock_levels_non_variant');
        $this->assertIndexAbsent('stock_levels', 'stock_levels_with_variant');

        // ── pre-T2 stock_levels unique constraint must be restored ────────────
        $this->assertConstraintExists('stock_levels', 'stock_levels_tenant_id_product_id_location_id_unique');

        // ── T2 partial unique indexes on channel_product_mappings must be gone ─
        $this->assertIndexAbsent('channel_product_mappings', 'channel_product_mappings_non_variant');
        $this->assertIndexAbsent('channel_product_mappings', 'channel_product_mappings_with_variant');

        // ── pre-T2 unique constraint on channel_product_mappings must be back ──
        $this->assertConstraintExists('channel_product_mappings', 'channel_product_variant_unique');

        // ── T2 partial unique indexes on price_list_items must be gone ─────────
        $this->assertIndexAbsent('price_list_items', 'price_list_items_non_variant');
        $this->assertIndexAbsent('price_list_items', 'price_list_items_with_variant');

        // ── pre-T2 unique constraint on price_list_items must be back ──────────
        $this->assertConstraintExists('price_list_items', 'price_list_product_qty_unique');

        // ── T2 partial unique indexes on product_batches must be gone ─────────
        $this->assertIndexAbsent('product_batches', 'product_batches_non_variant');
        $this->assertIndexAbsent('product_batches', 'product_batches_with_variant');

        // ── pre-T2 unique constraint on product_batches must be restored ──────
        $this->assertConstraintExists('product_batches', 'unique_batch_per_product');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Low-level schema helpers (PostgreSQL pg_indexes / pg_constraint)
    // ─────────────────────────────────────────────────────────────────────────

    private function indexExists(string $table, string $indexName): bool
    {
        $row = DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $indexName]
        );

        return $row !== null;
    }

    private function constraintExists(string $table, string $constraintName): bool
    {
        $row = DB::selectOne(
            'SELECT 1
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relname = ? AND c.conname = ?',
            [$table, $constraintName]
        );

        return $row !== null;
    }

    private function assertIndexExists(string $table, string $indexName, string $prefix = ''): void
    {
        $this->assertTrue(
            $this->indexExists($table, $indexName),
            $prefix."Index {$indexName} on {$table} should exist"
        );
    }

    private function assertIndexAbsent(string $table, string $indexName, string $prefix = ''): void
    {
        $this->assertFalse(
            $this->indexExists($table, $indexName),
            $prefix."Index {$indexName} on {$table} should not exist after rollback"
        );
    }

    private function assertConstraintExists(string $table, string $constraintName, string $prefix = ''): void
    {
        $this->assertTrue(
            $this->constraintExists($table, $constraintName),
            $prefix."Constraint {$constraintName} on {$table} should exist"
        );
    }

    private function assertConstraintAbsent(string $table, string $constraintName, string $prefix = ''): void
    {
        $this->assertFalse(
            $this->constraintExists($table, $constraintName),
            $prefix."Constraint {$constraintName} on {$table} should not exist"
        );
    }
}
