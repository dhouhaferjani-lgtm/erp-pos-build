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
        Schema::create('parapharmacy_product_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->unique();
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            // Product Classification
            $table->string('category', 50); // enum: supplement, cosmetic, medical_device, herbal, baby_care, sports_nutrition, other
            $table->string('dosage_form', 50)->nullable(); // enum: capsule, tablet, softgel, liquid, powder, cream, gel, lotion, spray, patch, other

            // Active Ingredients / Components
            $table->json('active_ingredients')->nullable(); // Array of {name: string, concentration?: string}
            $table->json('key_components')->nullable(); // Array of string for cosmetics/non-medicinal products

            // Usage Information
            $table->text('usage_instructions')->nullable();
            $table->text('warnings')->nullable();
            $table->text('contraindications')->nullable();

            // Restrictions & Regulations
            $table->integer('minimum_age')->nullable(); // Minimum age restriction
            $table->string('age_restriction', 50)->nullable(); // enum: adult_only, children_only, all_ages
            $table->boolean('requires_consultation')->default(false); // Optional pharmacist consultation
            $table->string('regulatory_code')->nullable(); // Country-specific regulatory codes

            // Health Claims & Certifications
            $table->json('health_claims')->nullable(); // Array of string
            $table->json('certifications')->nullable(); // Array of {type: string, code: string}

            // Storage & Handling
            $table->string('storage_requirements', 100)->nullable(); // "Store in cool, dry place", "Refrigerate", etc.

            $table->timestamps();

            // Indexes for common queries
            $table->index('category');
            $table->index('dosage_form');
            $table->index('age_restriction');
            $table->index('requires_consultation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parapharmacy_product_metadata');
    }
};
