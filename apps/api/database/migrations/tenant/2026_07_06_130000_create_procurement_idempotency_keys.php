<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('idempotency_key', 64);
            $table->uuid('purchase_order_id')->nullable();
            $table->uuid('goods_receipt_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'procurement_idempotency_company_key_unique');
            $table->index('tenant_id');
            $table->index('purchase_order_id');
            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_idempotency_keys');
    }
};
