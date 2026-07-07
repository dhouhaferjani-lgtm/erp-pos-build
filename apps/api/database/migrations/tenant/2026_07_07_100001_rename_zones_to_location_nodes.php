<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evolve flat location_zones → arbitrary-depth location_nodes (labels only)
 * and product_zone_assignments → product_placements (soft-deletable).
 *
 * Additive + backfill, zero data loss. Existing zones become top-level nodes
 * (node_type='zone', parent_id=NULL, path=code, depth=0). Old UNIQUE
 * constraints are replaced by PARTIAL uniques (WHERE deleted_at IS NULL) so
 * tombstoned codes/placements can be reused. See
 * docs/superpowers/specs/2026-07-07-location-placement-hierarchy-design.md §9.
 *
 * Driver notes: Blueprint->unique() creates a UNIQUE CONSTRAINT on Postgres
 * (dropped with DROP CONSTRAINT) but a plain unique INDEX on SQLite (dropped
 * with DROP INDEX). SQLite also cannot ADD CONSTRAINT (parent_id FK) or
 * ALTER COLUMN SET NOT NULL, and has no text_pattern_ops — those are
 * pgsql-only branches, matching the repo pattern in
 * 2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        // --- location_zones -> location_nodes ---
        Schema::rename('location_zones', 'location_nodes');

        Schema::table('location_nodes', function (Blueprint $table): void {
            $table->uuid('parent_id')->nullable()->after('location_id');
            $table->string('node_type', 32)->default('zone')->after('parent_id');
            $table->string('path', 512)->nullable()->after('code');
            $table->smallInteger('depth')->default(0)->after('path');
            $table->softDeletesTz();
            $table->index('parent_id', 'location_nodes_parent_idx');
        });

        // Backfill existing rows as top-level nodes.
        DB::statement("UPDATE location_nodes SET node_type = 'zone', depth = 0, path = code WHERE path IS NULL");

        if ($isPgsql) {
            DB::statement('ALTER TABLE location_nodes ALTER COLUMN path SET NOT NULL');
            DB::statement('ALTER TABLE location_nodes ADD CONSTRAINT location_nodes_parent_id_foreign
                FOREIGN KEY (parent_id) REFERENCES location_nodes (id) ON DELETE SET NULL');
            // Blueprint->unique() made a CONSTRAINT on PG — DROP INDEX would fail.
            DB::statement('ALTER TABLE location_nodes DROP CONSTRAINT IF EXISTS location_zones_location_code_unique');
            // Subtree prefix scans: text_pattern_ops so LIKE 'x/%' uses the index.
            DB::statement('CREATE INDEX location_nodes_location_path_idx ON location_nodes (location_id, path text_pattern_ops)');
        } else {
            DB::statement('DROP INDEX IF EXISTS location_zones_location_code_unique');
            DB::statement('CREATE INDEX location_nodes_location_path_idx ON location_nodes (location_id, path)');
        }

        DB::statement('CREATE UNIQUE INDEX location_nodes_location_code_live_unique
            ON location_nodes (location_id, code) WHERE deleted_at IS NULL');

        // --- product_zone_assignments -> product_placements ---
        Schema::rename('product_zone_assignments', 'product_placements');

        Schema::table('product_placements', function (Blueprint $table): void {
            $table->renameColumn('zone_id', 'node_id');
        });

        Schema::table('product_placements', function (Blueprint $table): void {
            $table->softDeletesTz();
        });

        if ($isPgsql) {
            DB::statement('ALTER TABLE product_placements DROP CONSTRAINT IF EXISTS product_zone_assignments_product_location_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS product_zone_assignments_product_location_unique');
        }

        DB::statement('CREATE UNIQUE INDEX product_placements_product_location_live_unique
            ON product_placements (product_id, location_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX product_placements_location_node_live_idx
            ON product_placements (location_id, node_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX product_placements_delta_idx
            ON product_placements (location_id, updated_at, id)');
    }

    public function down(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        // --- product_placements -> product_zone_assignments ---
        DB::statement('DROP INDEX IF EXISTS product_placements_product_location_live_unique');
        DB::statement('DROP INDEX IF EXISTS product_placements_location_node_live_idx');
        DB::statement('DROP INDEX IF EXISTS product_placements_delta_idx');

        Schema::table('product_placements', function (Blueprint $table): void {
            $table->dropColumn('deleted_at');
        });

        Schema::table('product_placements', function (Blueprint $table): void {
            $table->renameColumn('node_id', 'zone_id');
        });

        Schema::rename('product_placements', 'product_zone_assignments');

        if ($isPgsql) {
            DB::statement('ALTER TABLE product_zone_assignments ADD CONSTRAINT product_zone_assignments_product_location_unique UNIQUE (product_id, location_id)');
        } else {
            DB::statement('CREATE UNIQUE INDEX product_zone_assignments_product_location_unique ON product_zone_assignments (product_id, location_id)');
        }

        // --- location_nodes -> location_zones ---
        DB::statement('DROP INDEX IF EXISTS location_nodes_location_code_live_unique');
        DB::statement('DROP INDEX IF EXISTS location_nodes_location_path_idx');

        if ($isPgsql) {
            DB::statement('ALTER TABLE location_nodes DROP CONSTRAINT IF EXISTS location_nodes_parent_id_foreign');
        }

        Schema::table('location_nodes', function (Blueprint $table): void {
            $table->dropIndex('location_nodes_parent_idx');
            $table->dropColumn(['parent_id', 'node_type', 'path', 'depth', 'deleted_at']);
        });

        Schema::rename('location_nodes', 'location_zones');

        if ($isPgsql) {
            DB::statement('ALTER TABLE location_zones ADD CONSTRAINT location_zones_location_code_unique UNIQUE (location_id, code)');
        } else {
            DB::statement('CREATE UNIQUE INDEX location_zones_location_code_unique ON location_zones (location_id, code)');
        }
    }
};
