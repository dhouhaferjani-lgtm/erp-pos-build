<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_attribute_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignUuid('attribute_id')->constrained('product_attributes')->restrictOnDelete();
            $table->foreignUuid('attribute_value_id')->constrained('product_attribute_values')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['variant_id', 'attribute_id']);
            $table->index('attribute_value_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
