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
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        // stock_reservations has company_id (not tenant_id) — index uses company_id accordingly.
        DB::statement('CREATE INDEX CONCURRENTLY stock_reservations_company_product_variant_released_idx
            ON stock_reservations (company_id, product_id, variant_id, released_at)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_reservations_company_product_variant_released_idx');
            DB::statement('ALTER TABLE stock_reservations DROP CONSTRAINT IF EXISTS stock_reservations_variant_id_foreign');
        }

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
