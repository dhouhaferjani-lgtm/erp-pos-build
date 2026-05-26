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
        Schema::create('health_claim_product', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('health_claim_id');
            $table->integer('display_order')->default(0);
            $table->timestamps();

            // Foreign keys
            $table->foreign('product_id')
                ->references('product_id')->on('parapharmacy_product_metadata')
                ->onDelete('cascade');

            $table->foreign('health_claim_id')
                ->references('id')->on('health_claims')
                ->onDelete('restrict');

            // Indexes and constraints
            $table->unique(['product_id', 'health_claim_id']);
            $table->index('product_id', 'idx_health_claim_product_product');
            $table->index('health_claim_id', 'idx_health_claim_product_claim');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_claim_product');
    }
};
