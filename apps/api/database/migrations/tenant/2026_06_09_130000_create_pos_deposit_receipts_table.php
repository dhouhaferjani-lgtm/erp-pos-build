<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_deposit_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('fiscal_event_id')->unique();
            $table->string('deposit_receipt_uuid');
            $table->string('customer_id');
            $table->string('customer_name');
            $table->string('amount', 32);
            $table->string('currency_code', 3);
            $table->jsonb('payload_snapshot');
            $table->timestampsTz();

            // No FK on tenant_id: under database-per-tenant the `tenants` table
            // lives in the central DB, so a cross-database FK is impossible
            // (mirrors pos_account_payment_receipts).
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('fiscal_event_id')->references('id')->on('fiscal_events')->restrictOnDelete();

            $table->index(['tenant_id', 'company_id', 'customer_id'], 'pos_deposit_receipts_customer_idx');
            $table->index(['tenant_id', 'company_id', 'deposit_receipt_uuid'], 'pos_deposit_receipts_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_deposit_receipts');
    }
};
