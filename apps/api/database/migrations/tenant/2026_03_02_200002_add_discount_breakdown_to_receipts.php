<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->jsonb('discount_breakdown')->nullable()->after('discount_amount');
        });

        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->jsonb('discount_breakdown')->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropColumn('discount_breakdown');
        });

        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('discount_breakdown');
        });
    }
};
