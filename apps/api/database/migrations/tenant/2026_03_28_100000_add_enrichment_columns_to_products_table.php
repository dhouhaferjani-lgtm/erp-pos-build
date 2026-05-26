<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('platform_product_id')->nullable()->after('cost_updated_at');
            $table->uuid('platform_submission_id')->nullable()->after('platform_product_id');
            $table->string('enrichment_status', 20)->nullable()->after('platform_submission_id');

            $table->index(
                ['tenant_id', 'enrichment_status'],
                'idx_products_enrichment'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_enrichment');
            $table->dropColumn(['platform_product_id', 'platform_submission_id', 'enrichment_status']);
        });
    }
};
