<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY channel_product_mappings_non_variant
            ON channel_product_mappings (channel_id, product_id) WHERE variant_id IS NULL');

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY channel_product_mappings_with_variant
            ON channel_product_mappings (channel_id, product_id, variant_id) WHERE variant_id IS NOT NULL');

        DB::statement('ALTER TABLE channel_product_mappings DROP CONSTRAINT channel_product_variant_unique');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE channel_product_mappings ADD CONSTRAINT channel_product_variant_unique
            UNIQUE (channel_id, product_id, variant_id)');

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS channel_product_mappings_with_variant');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS channel_product_mappings_non_variant');
    }
};
