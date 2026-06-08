<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        $driver = DB::connection()->getDriverName();

        // SQLite (test suite): mirror the Postgres variant-grain uniqueness so a
        // product can be stocked once per location when it has NO variant, and
        // once per (product, variant) per location when it does. SQLite supports
        // partial unique indexes but not CONCURRENTLY / NOT VALID, and the legacy
        // constraint exists only as a named unique INDEX (not a table constraint),
        // so we DROP INDEX by name and recreate the two partial indexes. Without
        // this, the legacy (tenant, product, location) unique blocks multiple
        // variants of one product at one location — variant inventory would be
        // untestable on SQLite (the CI driver).
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS stock_levels_tenant_id_product_id_location_id_unique');
            DB::statement('CREATE UNIQUE INDEX stock_levels_non_variant
                           ON stock_levels (tenant_id, product_id, location_id)
                           WHERE variant_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX stock_levels_with_variant
                           ON stock_levels (tenant_id, product_id, variant_id, location_id)
                           WHERE variant_id IS NOT NULL');

            return;
        }

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_levels
            ADD CONSTRAINT stock_levels_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id)
            ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_levels_non_variant
                       ON stock_levels (tenant_id, product_id, location_id)
                       WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_levels_with_variant
                       ON stock_levels (tenant_id, product_id, variant_id, location_id)
                       WHERE variant_id IS NOT NULL');

        DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_tenant_id_product_id_location_id_unique');
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_tenant_id_product_id_location_id_unique
                           UNIQUE (tenant_id, product_id, location_id)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_levels_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_levels_non_variant');
            DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_variant_id_foreign');
        } elseif ($driver === 'sqlite') {
            // Symmetric SQLite teardown: drop the partial indexes and restore the
            // legacy (tenant, product, location) unique before the column drop.
            DB::statement('DROP INDEX IF EXISTS stock_levels_with_variant');
            DB::statement('DROP INDEX IF EXISTS stock_levels_non_variant');
            DB::statement('CREATE UNIQUE INDEX stock_levels_tenant_id_product_id_location_id_unique
                           ON stock_levels (tenant_id, product_id, location_id)');
        }
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
