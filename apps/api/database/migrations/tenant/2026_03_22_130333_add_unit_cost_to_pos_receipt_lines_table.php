<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
    }
};
