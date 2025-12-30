<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_tax_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();

            $table->string('tax_type', 20);      // TaxType enum: PERCENTAGE, FIXED_AMOUNT
            $table->string('tax_name', 100);     // Display name (e.g., "TVA 19%", "Stamp Duty")

            $table->decimal('tax_base', 15, 2)->nullable(); // Subtotal for percentage taxes, null for fixed
            $table->decimal('tax_rate', 5, 2)->nullable();  // Rate for percentage taxes, null for fixed
            $table->decimal('tax_amount', 15, 2);          // Calculated tax amount

            $table->boolean('is_stamp_duty')->default(false); // Flag to identify stamp duties

            $table->timestamps();

            // Indexes
            $table->index('document_id');
            $table->index(['document_id', 'tax_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_tax_details');
    }
};
