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
        Schema::create('key_component_product', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('component_id');
            $table->integer('order')->default(0);
            $table->timestamps();

            // Foreign keys
            $table->foreign('product_id')
                ->references('product_id')->on('parapharmacy_product_metadata')
                ->onDelete('cascade');

            $table->foreign('component_id')
                ->references('id')->on('product_key_components')
                ->onDelete('restrict');

            // Indexes and constraints
            $table->unique(['product_id', 'component_id']);
            $table->index('product_id', 'idx_key_component_product_product');
            $table->index('component_id', 'idx_key_component_product_component');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('key_component_product');
    }
};
