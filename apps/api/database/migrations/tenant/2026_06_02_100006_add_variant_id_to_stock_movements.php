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
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE INDEX CONCURRENTLY stock_movements_tenant_product_variant_created_idx
            ON stock_movements (tenant_id, product_id, variant_id, created_at)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_movements_tenant_product_variant_created_idx');
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_variant_id_foreign');
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
