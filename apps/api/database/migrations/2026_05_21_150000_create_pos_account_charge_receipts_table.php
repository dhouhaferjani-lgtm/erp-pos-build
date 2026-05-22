<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_account_charge_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('fiscal_event_id')->unique();
            $table->string('account_charge_uuid');
            $table->string('customer_id');
            $table->string('customer_name');
            $table->decimal('amount_charged', 15, 4);
            $table->string('currency_code', 3);
            $table->jsonb('payload_snapshot');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('fiscal_event_id')->references('id')->on('fiscal_events')->restrictOnDelete();

            $table->index(['tenant_id', 'company_id', 'customer_id'], 'pos_account_charge_receipts_customer_idx');
            $table->index(['tenant_id', 'company_id', 'account_charge_uuid'], 'pos_account_charge_receipts_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_account_charge_receipts');
    }
};
