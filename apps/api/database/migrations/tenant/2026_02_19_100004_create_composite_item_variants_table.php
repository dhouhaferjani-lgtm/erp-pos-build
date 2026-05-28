<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composite_item_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('composite_item_id')->constrained('composite_items')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->string('price_adjustment_type', 50)->default('absolute');
            $table->decimal('price_adjustment', 15, 4)->default(0);
            $table->decimal('recipe_multiplier', 8, 4)->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(['composite_item_id', 'code']);
            $table->index(['composite_item_id', 'is_active']);
        });

        // Partial unique index: only one default variant per composite item
        DB::statement('CREATE UNIQUE INDEX composite_item_variants_default_unique ON composite_item_variants (composite_item_id) WHERE is_default = TRUE');
    }

    public function down(): void
    {
        Schema::dropIfExists('composite_item_variants');
    }
};
