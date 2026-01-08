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
        Schema::create('certification_product', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('certification_id');
            $table->string('certification_code', 100)->nullable()->comment('Unique code on certificate (e.g., BIO-001234)');
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('verification_url', 500)->nullable()->comment('Product-specific verification link');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('product_id')
                ->references('product_id')->on('parapharmacy_product_metadata')
                ->onDelete('cascade');

            $table->foreign('certification_id')
                ->references('id')->on('certifications')
                ->onDelete('restrict');

            // Indexes and constraints
            $table->unique(['product_id', 'certification_id']);
            $table->index('product_id', 'idx_certification_product_product');
            $table->index('certification_id', 'idx_certification_product_certification');
            $table->index('expiry_date', 'idx_certification_product_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certification_product');
    }
};
