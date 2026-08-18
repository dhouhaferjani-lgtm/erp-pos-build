<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pos_receipt_lines', 'stock_movement_expected')) {
            return;
        }

        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            // Immutable projection outcome used by detector D-f. Existing
            // rows predate the Wave-3 watermark; true preserves the ordinary
            // sale/restock contract while new intentional no-op refunds write
            // false explicitly.
            $table->boolean('stock_movement_expected')->default(true)->after('disposition');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pos_receipt_lines', 'stock_movement_expected')) {
            return;
        }

        Schema::table('pos_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn('stock_movement_expected');
        });
    }
};
