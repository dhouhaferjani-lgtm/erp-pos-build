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
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropUnique('goods_receipts_company_id_receipt_number_unique');
            $table->string('receipt_number', 30)->nullable()->change();
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->decimal('landed_unit_cost', 19, 6)->nullable()->change();
            $table->decimal('accrual_unit_cost', 15, 6)->nullable()->change();
            $table->decimal('effective_unit_cost', 19, 6)->nullable()->change();
        });

        DB::statement(
            'CREATE UNIQUE INDEX goods_receipts_company_id_receipt_number_unique
                ON goods_receipts (company_id, receipt_number)
                WHERE receipt_number IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS goods_receipts_company_id_receipt_number_unique');

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->decimal('landed_unit_cost', 19, 6)->nullable(false)->change();
            $table->decimal('accrual_unit_cost', 15, 6)->nullable(false)->change();
            $table->decimal('effective_unit_cost', 19, 6)->nullable(false)->change();
        });

        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->string('receipt_number', 30)->nullable(false)->change();
            $table->unique(['company_id', 'receipt_number']);
        });
    }
};

