<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_exchange_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();

            // Client-supplied idempotency key (unique per company)
            $table->uuid('exchange_request_id');

            // The link committed into both halves' v3 hash payloads
            $table->uuid('exchange_group_id')->index();

            // Lifecycle state: pending | completed | failed
            $table->string('status', 32)->default('pending');

            // Populated once the two fiscal documents are created
            $table->uuid('return_receipt_id')->nullable();
            $table->uuid('sale_receipt_id')->nullable();

            // Populated when surplus routes to voucher
            $table->uuid('voucher_id')->nullable();

            // Failure audit fields
            $table->string('failure_reason', 255)->nullable();
            $table->jsonb('failure_payload')->nullable();

            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            // Foreign keys
            $table->foreign('return_receipt_id')->references('id')->on('pos_receipts')->nullOnDelete();
            $table->foreign('sale_receipt_id')->references('id')->on('pos_receipts')->nullOnDelete();
            $table->foreign('voucher_id')->references('id')->on('vouchers')->nullOnDelete();

            // Idempotency constraint: same client key per company must resolve to same triple
            $table->unique(['company_id', 'exchange_request_id']);

            // Monitoring: status per tenant
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_exchange_requests');
    }
};
