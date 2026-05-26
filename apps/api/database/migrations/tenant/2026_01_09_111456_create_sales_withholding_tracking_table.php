<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_withholding_tracking', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained('companies');

            // Source
            $table->foreignUuid('document_id')->constrained('documents');
            $table->foreignUuid('payment_id')->nullable()->constrained('payments');

            // Customer who withheld
            $table->foreignUuid('customer_id')->constrained('partners');

            // Amounts
            $table->decimal('invoice_amount', 15, 2);
            $table->decimal('withholding_rate', 5, 4);
            $table->decimal('withholding_amount', 15, 2);
            $table->decimal('expected_receivable', 15, 2);

            // Certificate from customer
            $table->string('certificate_number', 100)->nullable();
            $table->boolean('certificate_received')->default(false);
            $table->timestamp('certificate_received_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('document_id');
            $table->index('customer_id');
            $table->index('certificate_received');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_withholding_tracking');
    }
};
