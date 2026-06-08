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
        Schema::table('product_batches', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE product_batches ADD CONSTRAINT product_batches_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY product_batches_non_variant
            ON product_batches (company_id, product_id, batch_number) WHERE variant_id IS NULL');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY product_batches_with_variant
            ON product_batches (company_id, product_id, variant_id, batch_number) WHERE variant_id IS NOT NULL');

        DB::statement('ALTER TABLE product_batches DROP CONSTRAINT unique_batch_per_product');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_batches ADD CONSTRAINT unique_batch_per_product
                UNIQUE (company_id, product_id, batch_number)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_batches_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_batches_non_variant');
            DB::statement('ALTER TABLE product_batches DROP CONSTRAINT IF EXISTS product_batches_variant_id_foreign');
        }

        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn('variant_id');
        });
    }
};
