<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delete products with type='service' (seed data only, no production data yet)
        DB::table('products')->where('type', 'service')->delete();

        // Make type nullable and set existing values to NULL
        Schema::table('products', function (Blueprint $table) {
            $table->string('type')->nullable()->default(null)->change();
        });

        // Set all remaining products' type to NULL
        DB::table('products')->whereNotNull('type')->update(['type' => null]);

        // Add index for the new query pattern
        Schema::table('products', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_physical'], 'idx_products_tenant_physical');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_tenant_physical');
        });

        // Restore type to non-nullable with default 'part'
        DB::table('products')->whereNull('type')->update(['type' => 'part']);

        Schema::table('products', function (Blueprint $table) {
            $table->string('type')->nullable(false)->default('part')->change();
        });
    }
};
