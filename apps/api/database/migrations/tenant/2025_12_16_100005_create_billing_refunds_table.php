<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->decimal('amount', 12, 2);
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
