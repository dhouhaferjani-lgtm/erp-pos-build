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
        Schema::table('document_lines', function (Blueprint $table) {
            $table->decimal('allocated_costs', 12, 2)->default(0);
            $table->decimal('landed_unit_cost', 12, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropColumn([
                'allocated_costs',
                'landed_unit_cost',
            ]);
        });
    }
};
