<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL table — platform billing (invoices the platform issues to tenants).
 * Moved out of database/migrations/tenant/ on 2026-06-12: these tables were
 * misfiled during the T6 db-per-tenant flip and must live in the central DB
 * (read from central context by AdminBillingController / MonitoringService /
 * StripeWebhookController / InvoiceService). Monetary scales baked in at 3
 * (precision contract): any DB that previously ran this file (pre-flip shared
 * DBs, tenant DBs) also ran the 2026_03_11 widen migration, so it already has
 * scale 3 and skips this file via its migration ledger; fresh central DBs get
 * scale 3 directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');

            // Provider info
            $table->string('provider_refund_id')->nullable();

            // Status: pending, processing, succeeded, failed
            $table->string('status')->default('pending');

            // Amount
            $table->decimal('amount', 12, 3);
            $table->string('currency', 3)->default('EUR');

            // Reason
            $table->string('reason')->nullable(); // duplicate, fraudulent, requested_by_customer, etc.
            $table->text('notes')->nullable();

            // Who initiated
            $table->uuid('initiated_by')->nullable();

            // Error tracking
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Timestamps
            $table->timestamp('refunded_at')->nullable();

            // Metadata
            $table->jsonb('metadata')->default('{}');

            $table->timestamps();

            $table->foreign('payment_id')
                ->references('id')
                ->on('billing_payments')
                ->cascadeOnDelete();

            $table->index('payment_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refunds');
    }
};
