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
        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT VALID');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY price_list_items_non_variant
            ON price_list_items (price_list_id, product_id, min_quantity) WHERE variant_id IS NULL');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY price_list_items_with_variant
            ON price_list_items (price_list_id, product_id, variant_id, min_quantity) WHERE variant_id IS NOT NULL');

        DB::statement('ALTER TABLE price_list_items DROP CONSTRAINT price_list_product_qty_unique');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT price_list_product_qty_unique
                UNIQUE (price_list_id, product_id, min_quantity)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_list_items_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS price_list_items_non_variant');
            DB::statement('ALTER TABLE price_list_items DROP CONSTRAINT IF EXISTS price_list_items_variant_id_foreign');
        }

        Schema::table('price_list_items', function (Blueprint $table): void {
            $table->dropColumn('variant_id');
        });
    }
};
