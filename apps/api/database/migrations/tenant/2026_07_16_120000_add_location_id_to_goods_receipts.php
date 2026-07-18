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
            $table->index(['tenant_id', 'company_id', 'location_id'], 'goods_receipts_tenant_company_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropIndex('goods_receipts_tenant_company_location_idx');
            $table->dropColumn('location_id');
        });
    }
};
