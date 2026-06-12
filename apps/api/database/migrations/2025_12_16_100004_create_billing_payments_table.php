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
 * (precision contract) since no central DB has ever run this file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id')->nullable();

            // Provider info
            $table->string('provider'); // stripe, paypal, manual, bank_transfer, cash, check, flouci, etc.
            $table->string('provider_payment_id')->nullable();

            // Status: pending, processing, requires_action, succeeded, failed, cancelled, refunded, partially_refunded
            $table->string('status')->default('pending');

            // Amounts
            $table->decimal('amount', 12, 3);
            $table->decimal('fee', 12, 3)->default(0);
            $table->decimal('net_amount', 12, 3);
            $table->string('currency', 3)->default('EUR');

            // Refund tracking
            $table->decimal('refunded_amount', 12, 3)->default(0);

            // Payment method details (card last 4, bank name, etc.)
            $table->string('payment_method_type')->nullable(); // card, bank_transfer, cash, check
            $table->jsonb('payment_method_details')->default('{}');

            // Manual payment specifics
            $table->string('reference_number')->nullable(); // Check number, transfer reference
            $table->date('payment_date')->nullable(); // For manual: actual payment date
            $table->uuid('recorded_by')->nullable(); // Admin who recorded manual payment

            // For 3DS / action required
            $table->string('client_secret')->nullable();
            $table->string('action_url')->nullable();

            // Error tracking
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Timestamps
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // Metadata
            $table->jsonb('metadata')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->nullOnDelete();

            $table->index('tenant_id');
            $table->index('invoice_id');
            $table->index('provider');
            $table->index('status');
            $table->index('paid_at');
            $table->index(['provider', 'provider_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
