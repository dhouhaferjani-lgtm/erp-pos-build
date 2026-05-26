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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('inventory_costing_method', 20)->default('weighted_average');
            $table->decimal('default_target_margin', 5, 2)->default(30.00);
            $table->decimal('default_minimum_margin', 5, 2)->default(10.00);
            $table->boolean('allow_below_cost_sales')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'inventory_costing_method',
                'default_target_margin',
                'default_minimum_margin',
                'allow_below_cost_sales',
            ]);
        });
    }
};
