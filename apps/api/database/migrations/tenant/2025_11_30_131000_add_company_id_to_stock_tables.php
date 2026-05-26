<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.1.7: Add company_id to stock tables.
 *
 * Stock tables (stock_levels, stock_movements) already have location_id
 * which references a location that belongs to a company. Adding company_id
 * as a denormalized column for easier company-scoped queries without joins.
 *
 * This enables efficient queries like:
 * - Get all stock for a company across all locations
 * - Filter stock movements by company
 * - Company-level inventory reports
 *
 * Note: company_id is nullable initially to allow gradual data migration.
 * A future migration will make it required after data is migrated.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Stock levels
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'product_id'], 'idx_stock_levels_company_product');
            $table->index(['company_id', 'location_id'], 'idx_stock_levels_company_location');
        });

        // Stock movements
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->after('tenant_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'created_at'], 'idx_stock_movements_company_date');
            $table->index(['company_id', 'movement_type'], 'idx_stock_movements_company_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_stock_movements_company_date');
            $table->dropIndex('idx_stock_movements_company_type');
            $table->dropColumn('company_id');
        });

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_stock_levels_company_product');
            $table->dropIndex('idx_stock_levels_company_location');
            $table->dropColumn('company_id');
        });
    }
};
