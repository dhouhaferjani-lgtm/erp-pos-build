<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_category_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('menu_category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->foreignUuid('composite_item_id')->constrained('composite_items')->cascadeOnDelete();
            $table->decimal('override_price', 15, 4)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['menu_category_id', 'composite_item_id'], 'menu_cat_item_unique');
            $table->index(['menu_category_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_category_items');
    }
};
