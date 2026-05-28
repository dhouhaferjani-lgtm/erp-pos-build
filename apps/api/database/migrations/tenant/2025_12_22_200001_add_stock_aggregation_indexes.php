<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite index for efficient stock aggregation queries.
     *
     * This index optimizes queries that:
     * - Calculate total stock across all locations for a product
     * - Filter stock levels by company and product
     * - Used in product search with stock display
     */
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->index(['product_id', 'company_id'], 'idx_stock_product_company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropIndex('idx_stock_product_company');
        });
    }
};
