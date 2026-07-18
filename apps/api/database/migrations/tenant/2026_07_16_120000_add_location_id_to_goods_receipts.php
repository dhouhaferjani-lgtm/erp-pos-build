<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->uuid('location_id')->nullable()->after('purchase_order_id');
            $table->index(['company_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropIndex('goods_receipts_company_id_location_id_index');
            $table->dropColumn('location_id');
        });
    }
};
