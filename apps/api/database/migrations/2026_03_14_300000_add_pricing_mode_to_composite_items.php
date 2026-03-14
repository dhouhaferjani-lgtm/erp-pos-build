<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composite_items', function (Blueprint $table) {
            $table->string('pricing_mode', 50)->default('standard')->after('production_type');
        });
    }

    public function down(): void
    {
        Schema::table('composite_items', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
