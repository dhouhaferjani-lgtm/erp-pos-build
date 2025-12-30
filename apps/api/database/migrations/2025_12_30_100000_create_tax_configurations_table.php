<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('country_code', 2);
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();

            $table->string('tax_type', 20); // TaxType enum: PERCENTAGE, FIXED_AMOUNT
            $table->string('name', 100);
            $table->string('code', 20)->nullable();

            // Tax rate fields - one will be populated based on tax_type
            $table->decimal('percentage_rate', 5, 2)->nullable(); // For percentage taxes (e.g., 19.00)
            $table->decimal('fixed_amount', 10, 3)->nullable();   // For fixed taxes (e.g., 1.000 TND)

            $table->string('applies_to', 20); // TaxApplicationLevel enum: LINE_ITEMS, DOCUMENT_TOTAL

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->jsonb('metadata')->nullable(); // Country-specific config

            $table->timestamps();

            // Indexes
            $table->index(['country_code', 'is_active']);
            $table->index(['country_code', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_configurations');
    }
};
