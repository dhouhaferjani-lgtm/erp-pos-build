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

        if (DB::connection()->getDriverName() !== 'pgsql') {
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
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_tenant_id_product_id_location_id_unique
                           UNIQUE (tenant_id, product_id, location_id)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_levels_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_levels_non_variant');
            DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT IF EXISTS stock_levels_variant_id_foreign');
        }
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
