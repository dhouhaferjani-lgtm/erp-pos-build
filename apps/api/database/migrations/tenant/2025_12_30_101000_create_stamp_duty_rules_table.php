<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_duty_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('country_code', 2);
            $table->foreign('country_code')->references('code')->on('countries')->cascadeOnDelete();

            $table->string('document_type', 50);     // Maps to DocumentType enum (e.g., 'invoice')
            $table->string('fiscal_category', 50)->nullable(); // Maps to FiscalCategory enum (e.g., 'TAX_INVOICE', 'FISCAL_RECEIPT')

            $table->decimal('stamp_amount', 10, 3); // Fixed stamp amount (e.g., 1.000 TND)

            $table->boolean('is_active')->default(true);

            // Effective date range for historical tracking
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->jsonb('metadata')->nullable(); // Additional country-specific config

            $table->timestamps();

            // Unique constraint: one rule per country/type/category/date combination
            $table->unique(['country_code', 'document_type', 'fiscal_category', 'effective_from'], 'stamp_duty_unique');

            // Indexes
            $table->index(['country_code', 'is_active', 'effective_from']);
            $table->index(['document_type', 'fiscal_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_duty_rules');
    }
};
