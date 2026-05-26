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
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('target_margin_override', 5, 2)->nullable();
            $table->decimal('minimum_margin_override', 5, 2)->nullable();
            $table->decimal('last_purchase_cost', 12, 2)->nullable();
            $table->timestamp('cost_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'cost_price',
                'target_margin_override',
                'minimum_margin_override',
                'last_purchase_cost',
                'cost_updated_at',
            ]);
        });
    }
};
