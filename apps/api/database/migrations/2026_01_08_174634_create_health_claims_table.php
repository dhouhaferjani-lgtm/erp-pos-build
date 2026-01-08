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
        Schema::create('health_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('claim_type', 50)->comment('function, reduction_of_disease_risk, development_and_health');
            $table->string('slug', 255)->unique();
            $table->string('regulatory_status', 50)->default('approved')->comment('approved, pending, rejected');
            $table->string('efsa_reference', 100)->nullable()->comment('European Food Safety Authority reference');
            $table->string('fda_reference', 100)->nullable()->comment('FDA reference if applicable');
            $table->jsonb('country_restrictions')->nullable()->comment('{FR: true, TN: false, ...}');
            $table->boolean('requires_disclaimer')->default(false);
            $table->timestamps();

            // Indexes
            $table->index('claim_type', 'idx_health_claims_type');
            $table->index('slug', 'idx_health_claims_slug');
            $table->index('regulatory_status', 'idx_health_claims_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_claims');
    }
};
