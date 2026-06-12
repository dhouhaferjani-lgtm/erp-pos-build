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
        Schema::create('billing_invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');

            // Item details
            $table->string('description');
            $table->text('long_description')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 3);
            $table->decimal('amount', 12, 3);

            // Tax
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);

            // Discount
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 3)->default(0);

            // Period (for subscription items)
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // Reference (e.g., plan_id, addon_id)
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();

            // Ordering
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Metadata
            $table->jsonb('metadata')->default('{}');

            $table->timestamps();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('billing_invoices')
                ->cascadeOnDelete();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_items');
    }
};
