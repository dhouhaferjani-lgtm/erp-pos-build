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
        Schema::create('product_key_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 255)->unique();
            $table->boolean('is_allergen')->default(false);
            $table->timestamps();

            // Indexes
            $table->index('slug', 'idx_key_components_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_key_components');
    }
};
