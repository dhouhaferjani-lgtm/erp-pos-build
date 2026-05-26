<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_note_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->unique();
            $table->foreign('document_id')->references('id')->on('documents')->onDelete('cascade');

            // Return details
            $table->string('return_reason', 50); // enum: defective, wrong_item, customer_regret, damaged_in_transit, warranty, exchange, other
            $table->string('return_condition', 50)->nullable(); // enum: unopened, used, damaged, unusable
            $table->string('refund_method', 50)->nullable(); // enum: original_payment, store_credit, exchange, none

            // Source document links
            $table->uuid('source_delivery_note_id')->nullable();
            $table->uuid('source_invoice_id')->nullable();
            $table->uuid('linked_credit_note_id')->nullable(); // If return triggers a credit

            // Additional context
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index('source_delivery_note_id');
            $table->index('source_invoice_id');
            $table->index('linked_credit_note_id');
            $table->index('return_reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_note_metadata');
    }
};
