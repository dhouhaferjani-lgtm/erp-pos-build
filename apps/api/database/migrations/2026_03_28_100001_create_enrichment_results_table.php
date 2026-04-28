<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->uuid('tracking_id');
            $table->string('status', 20)->default('pending_review');
            $table->jsonb('enriched_data');
            $table->string('enrichment_quality', 10);
            $table->string('assigned_barcode', 50)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->jsonb('accepted_fields')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['product_id'], 'idx_enrichment_results_product');
            $table->index(['tenant_id', 'company_id', 'status'], 'idx_enrichment_results_status');
            $table->unique(['tracking_id'], 'idx_enrichment_results_tracking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_results');
    }
};
