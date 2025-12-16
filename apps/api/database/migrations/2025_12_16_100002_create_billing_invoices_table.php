<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id')->nullable();

            // Invoice number (globally unique for compliance)
            $table->string('number')->unique();

            // Status: draft, pending, sent, paid, partially_paid, overdue, cancelled, refunded
            $table->string('status')->default('draft');

            // Amounts
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->string('currency', 3)->default('EUR');

            // Tax details
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_number')->nullable();

            // Billing details (snapshot at invoice time)
            $table->jsonb('billing_address')->default('{}');
            $table->string('billing_email')->nullable();
            $table->string('billing_name')->nullable();

            // Important dates
            $table->date('invoice_date');
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Period covered
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // PDF storage
            $table->string('pdf_path')->nullable();

            // Notes
            $table->text('notes')->nullable();
            $table->text('footer_text')->nullable();

            // Stripe integration
            $table->string('stripe_invoice_id')->nullable()->unique();

            // Metadata
            $table->jsonb('metadata')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('subscription_id')
                ->references('id')
                ->on('tenant_subscriptions')
                ->nullOnDelete();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('due_date');
            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
