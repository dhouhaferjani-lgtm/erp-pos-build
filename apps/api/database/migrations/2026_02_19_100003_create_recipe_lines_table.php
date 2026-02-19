<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->string('component_type', 50)->default('product');
            $table->uuid('component_id');
            $table->decimal('quantity', 15, 4);
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_scalable')->default(true);
            $table->decimal('wastage_percent', 5, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('line_cost', 15, 4)->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['recipe_id', 'display_order']);
            $table->index(['component_type', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
    }
};
