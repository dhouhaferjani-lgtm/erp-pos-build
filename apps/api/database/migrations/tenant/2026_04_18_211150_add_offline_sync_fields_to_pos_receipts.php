<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // consumption_mode already exists (migration 2026_01_08_190637).
        Schema::table('pos_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_receipts', 'table_id')) {
                $table->uuid('table_id')->nullable()->after('consumption_mode');
                $table->foreign('table_id')->references('id')->on('pos_tables')->nullOnDelete();
            }
            if (! Schema::hasColumn('pos_receipts', 'fiscal_status')) {
                $table->string('fiscal_status', 20)->default('fiscalized')->after('table_id');
                $table->index('fiscal_status', 'idx_pos_receipts_fiscal_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            if (Schema::hasColumn('pos_receipts', 'fiscal_status')) {
                $table->dropIndex('idx_pos_receipts_fiscal_status');
                $table->dropColumn('fiscal_status');
            }
            if (Schema::hasColumn('pos_receipts', 'table_id')) {
                $table->dropForeign(['table_id']);
                $table->dropColumn('table_id');
            }
        });
    }
};
