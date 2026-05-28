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
        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 255)->unique();
            $table->string('cas_number', 50)->nullable()->comment('Chemical Abstract Service number');
            $table->boolean('is_allergen')->default(false);
            $table->string('allergen_code', 20)->nullable()->comment('EU allergen code (e.g., EU14)');
            $table->string('regulatory_status', 50)->nullable()->comment('approved, restricted, banned');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('slug', 'idx_ingredients_slug');
            $table->index('is_allergen', 'idx_ingredients_allergen');
            $table->index('regulatory_status', 'idx_ingredients_regulatory');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
