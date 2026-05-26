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
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('sku', 100);
            $table->string('type', 20); // part, service, consumable
            $table->text('description')->nullable();
            $table->decimal('sale_price', 15, 2)->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('unit', 50)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('oem_numbers')->nullable();
            $table->json('cross_references')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'barcode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
