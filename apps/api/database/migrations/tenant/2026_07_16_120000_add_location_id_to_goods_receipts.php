<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('goods_receipts', 'location_id')) {
            Schema::table('goods_receipts', function (Blueprint $table): void {
                $table->uuid('location_id')->nullable()->after('purchase_order_id');
                $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
                $table->index(['tenant_id', 'company_id', 'location_id'], 'goods_receipts_tenant_company_location_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('goods_receipts', 'location_id')) {
            Schema::table('goods_receipts', function (Blueprint $table): void {
                $table->dropForeign(['location_id']);
                $table->dropIndex('goods_receipts_tenant_company_location_idx');
                $table->dropColumn('location_id');
            });
        }
    }
};
