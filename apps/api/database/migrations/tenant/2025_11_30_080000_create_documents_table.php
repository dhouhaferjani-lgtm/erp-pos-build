<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('partner_id')->constrained('partners');
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('type', 20); // quote, sales_order, invoice, credit_note, delivery_note
            $table->string('status', 20)->default('draft'); // draft, confirmed, posted, cancelled
            $table->string('document_number', 50);
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->decimal('balance_due', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('reference', 100)->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->jsonb('payload')->nullable(); // For additional data per document type
            $table->timestamps();
            $table->softDeletes();

            // Unique document number per tenant and type
            $table->unique(['tenant_id', 'type', 'document_number']);

            // Common query indexes
            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['tenant_id', 'partner_id']);
            $table->index(['tenant_id', 'document_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
