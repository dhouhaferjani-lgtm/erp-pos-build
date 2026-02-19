<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Multi-tenancy
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Product reference
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            // $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete(); // TODO: Add when product_variants table exists

            // Batch identity
            $table->string('batch_number', 100);

            // Dates
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date');

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_expired')->default(false)->comment('Computed by daily job');

            // Recall support
            $table->boolean('is_recalled')->default(false);
            $table->string('recall_reason')->nullable();
            $table->timestamp('recalled_at')->nullable();

            // Metadata
            $table->text('notes')->nullable();

            $table->timestamps();

            // Constraints
            $table->unique(['company_id', 'product_id', 'batch_number'], 'unique_batch_per_product');

            // Indexes
            $table->index(['company_id', 'expiry_date'], 'idx_batches_expiry');
            $table->index('product_id', 'idx_batches_product');
            $table->index(['is_active', 'is_recalled', 'is_expired'], 'idx_batches_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
