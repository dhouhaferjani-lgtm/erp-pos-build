<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composite_items', function (Blueprint $table): void {
            $table->decimal('manual_cost', 15, 4)->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('composite_items', function (Blueprint $table): void {
            $table->dropColumn('manual_cost');
        });
    }
};
