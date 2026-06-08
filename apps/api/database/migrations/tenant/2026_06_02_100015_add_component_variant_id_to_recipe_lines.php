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
        Schema::table('recipe_lines', function (Blueprint $table): void {
            $table->uuid('component_variant_id')->nullable()->after('component_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_component_variant_id_foreign
            FOREIGN KEY (component_variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY recipe_lines_component_variant_id_idx
            ON recipe_lines (component_variant_id)');

        DB::statement("ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_variant_requires_product
            CHECK (component_variant_id IS NULL OR component_type = 'product') NOT VALID");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_lines DROP CONSTRAINT IF EXISTS recipe_lines_variant_requires_product');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS recipe_lines_component_variant_id_idx');
            DB::statement('ALTER TABLE recipe_lines DROP CONSTRAINT IF EXISTS recipe_lines_component_variant_id_foreign');
        }

        Schema::table('recipe_lines', function (Blueprint $table): void {
            $table->dropColumn('component_variant_id');
        });
    }
};
