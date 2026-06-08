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
        Schema::table('catalog_cart_items', function (Blueprint $table): void {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE catalog_cart_items ADD CONSTRAINT catalog_cart_items_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY catalog_cart_items_cart_product_variant_idx
            ON catalog_cart_items (cart_id, product_id, variant_id)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS catalog_cart_items_cart_product_variant_idx');
            DB::statement('ALTER TABLE catalog_cart_items DROP CONSTRAINT IF EXISTS catalog_cart_items_variant_id_foreign');
        }

        Schema::table('catalog_cart_items', function (Blueprint $table): void {
            $table->dropColumn('variant_id');
        });
    }
};
