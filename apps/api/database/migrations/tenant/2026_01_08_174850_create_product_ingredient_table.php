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
        Schema::create('product_ingredient', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id')->comment('References parapharmacy_product_metadata.product_id');
            $table->uuid('ingredient_id');
            $table->string('concentration', 100)->nullable()->comment('e.g., 1000mg, 10%, 500 IU');
            $table->string('concentration_unit', 20)->nullable()->comment('mg, g, %, iu, mcg');
            $table->decimal('concentration_numeric', 12, 4)->nullable()->comment('Normalized for sorting/filtering');
            $table->integer('order')->default(0)->comment('Display order - primary ingredient first');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('product_id')
                ->references('product_id')->on('parapharmacy_product_metadata')
                ->onDelete('cascade');

            $table->foreign('ingredient_id')
                ->references('id')->on('ingredients')
                ->onDelete('restrict');

            // Indexes and constraints
            $table->unique(['product_id', 'ingredient_id']);
            $table->index('product_id', 'idx_product_ingredient_product');
            $table->index('ingredient_id', 'idx_product_ingredient_ingredient');
            $table->index('concentration_numeric', 'idx_product_ingredient_concentration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_ingredient');
    }
};
