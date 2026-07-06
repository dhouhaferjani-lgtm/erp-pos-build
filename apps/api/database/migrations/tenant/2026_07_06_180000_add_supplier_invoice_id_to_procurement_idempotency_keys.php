<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('supplier_invoice_id')->nullable()->after('goods_receipt_id');
            $table->index('supplier_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex(['supplier_invoice_id']);
            $table->dropColumn('supplier_invoice_id');
        });
    }
};
