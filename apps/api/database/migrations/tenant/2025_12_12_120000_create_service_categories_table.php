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
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Category information
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Hierarchical structure (self-referencing) - FK added separately
            $table->uuid('parent_id')->nullable();

            // Ordering
            $table->integer('sort_order')->default(0);

            // Status
            $table->boolean('is_active')->default(true);

            // Timestamps
            $table->timestamps();

            // Constraints
            $table->unique(['company_id', 'name'], 'uk_service_categories_name_company');

            // Indexes
            $table->index(['tenant_id']);
            $table->index(['company_id']);
            $table->index(['company_id', 'is_active']);
            $table->index(['parent_id']);
        });

        // Add self-referencing FK after table exists
        Schema::table('service_categories', function (Blueprint $table): void {
            $table->foreign('parent_id')
                ->references('id')
                ->on('service_categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
