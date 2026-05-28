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
        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('composite_item_id')->constrained('composite_items')->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->string('version_name', 255)->nullable();
            $table->boolean('is_active')->default(false);
            $table->decimal('yield_quantity', 15, 4)->default(1);
            $table->foreignUuid('yield_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('calculated_cost', 15, 4)->nullable();
            $table->integer('prep_time_minutes')->nullable();
            $table->integer('cook_time_minutes')->nullable();
            $table->integer('total_time_minutes')->nullable()->storedAs('COALESCE(prep_time_minutes, 0) + COALESCE(cook_time_minutes, 0)');
            $table->text('instructions')->nullable();
            $table->timestamps();

            $table->index(['composite_item_id', 'is_active']);
        });

        // Partial unique index: only one active recipe per composite item
        DB::statement('CREATE UNIQUE INDEX recipes_composite_item_active_unique ON recipes (composite_item_id) WHERE is_active = TRUE');

        // Now add the FK constraint on composite_items.default_recipe_id
        Schema::table('composite_items', function (Blueprint $table) {
            $table->foreign('default_recipe_id')->references('id')->on('recipes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('composite_items', function (Blueprint $table) {
            $table->dropForeign(['default_recipe_id']);
        });
        Schema::dropIfExists('recipes');
    }
};
