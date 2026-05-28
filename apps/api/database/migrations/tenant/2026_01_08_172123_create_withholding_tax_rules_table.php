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
        Schema::create('withholding_tax_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Scope
            $table->char('country_code', 2);
            $table->uuid('company_id')->nullable();

            // Rule identity
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Conditions
            $table->string('transaction_type', 50)->nullable();
            $table->string('partner_tax_status', 50)->nullable();
            $table->decimal('min_amount', 15, 3)->nullable();

            // Rate
            $table->decimal('rate', 5, 4);

            // Validity
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            // Indexes
            $table->unique(['country_code', 'code', 'effective_from']);
            $table->index(['country_code', 'is_active']);
            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withholding_tax_rules');
    }
};
