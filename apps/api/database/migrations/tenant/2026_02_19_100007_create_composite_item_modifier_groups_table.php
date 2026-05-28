<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composite_item_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('composite_item_id')->constrained('composite_items')->cascadeOnDelete();
            $table->foreignUuid('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(['composite_item_id', 'modifier_group_id'], 'ci_mg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composite_item_modifier_groups');
    }
};
