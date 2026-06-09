<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2 — make stock transfer lines variant-aware.
 *
 * A line may now target a specific product variant. variant_id is a nullable
 * uuid (NULL = product-level line, matching the legacy behaviour and the
 * non-variant stock_levels row). The old (transfer_id, product_id) unique is
 * replaced by two partial uniques so the SAME product can appear on multiple
 * lines under different variants while still forbidding a duplicate
 * (transfer, product, variant) line.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('stock_transfer_lines', function (Blueprint $table): void {
            $table->uuid('variant_id')->nullable()->after('product_id');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite (tests): drop the old composite unique, add partial uniques.
            DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_transfer_product_unique');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_non_variant
                ON stock_transfer_lines (transfer_id, product_id) WHERE variant_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_with_variant
                ON stock_transfer_lines (transfer_id, product_id, variant_id) WHERE variant_id IS NOT NULL');

            return;
        }

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_variant_id_foreign
            FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT VALID');

        DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_transfer_product_unique');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_transfer_lines_non_variant
            ON stock_transfer_lines (transfer_id, product_id) WHERE variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_transfer_lines_with_variant
            ON stock_transfer_lines (transfer_id, product_id, variant_id) WHERE variant_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_transfer_lines_with_variant');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS stock_transfer_lines_non_variant');
            DB::statement('ALTER TABLE stock_transfer_lines DROP CONSTRAINT IF EXISTS stock_transfer_lines_variant_id_foreign');
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY stock_transfer_lines_transfer_product_unique
                ON stock_transfer_lines (transfer_id, product_id)');
        } else {
            DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_with_variant');
            DB::statement('DROP INDEX IF EXISTS stock_transfer_lines_non_variant');
            DB::statement('CREATE UNIQUE INDEX stock_transfer_lines_transfer_product_unique
                ON stock_transfer_lines (transfer_id, product_id)');
        }

        Schema::table('stock_transfer_lines', function (Blueprint $table): void {
            $table->dropColumn('variant_id');
        });
    }
};
