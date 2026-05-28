<?php

declare(strict_types=1);

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
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Basic information
            $table->string('code', 50);
            $table->string('name', 255);
            $table->text('description')->nullable();

            // Categorization
            $table->uuid('category_id')->nullable();
            $table->foreign('category_id')
                ->references('id')
                ->on('service_categories')
                ->nullOnDelete();

            // Pricing
            $table->string('pricing_type', 20)->default('flat_rate'); // flat_rate, hourly, percentage
            $table->decimal('base_price', 15, 2)->default(0);
            $table->char('currency', 3)->default('TND');

            // Time-based pricing fields
            $table->integer('default_duration_minutes')->nullable();
            $table->decimal('hourly_rate', 15, 2)->nullable();

            // Tax
            $table->decimal('tax_rate', 5, 2)->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Constraints
            $table->unique(['company_id', 'code'], 'uk_services_code_company');

            // Indexes
            $table->index(['tenant_id']);
            $table->index(['company_id']);
            $table->index(['category_id']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'pricing_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
