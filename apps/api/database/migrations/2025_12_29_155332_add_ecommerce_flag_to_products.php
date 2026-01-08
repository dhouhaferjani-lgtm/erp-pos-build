<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_active_for_ecommerce')
                ->default(false)
                ->after('is_active')
                ->comment('When true, product images can be accessed publicly via rate-limited endpoints');

            // Index for efficient querying of e-commerce products
            $table->index(['tenant_id', 'is_active_for_ecommerce'], 'idx_products_tenant_ecommerce');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_tenant_ecommerce');
            $table->dropColumn('is_active_for_ecommerce');
        });
    }
};
