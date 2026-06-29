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
            $table->boolean('physical_receipt')->nullable()->after('quantity');
            $table->boolean('resalable')->nullable()->after('physical_receipt');
            $table->string('disposition', 32)->nullable()->after('resalable');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn(['physical_receipt', 'resalable', 'disposition']);
        });
    }
};
