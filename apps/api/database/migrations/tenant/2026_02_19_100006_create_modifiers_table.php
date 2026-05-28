<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->decimal('price_adjustment', 15, 4)->default(0);
            $table->string('component_type', 50)->nullable();
            $table->uuid('component_id')->nullable();
            $table->decimal('component_quantity', 15, 4)->nullable();
            $table->foreignUuid('component_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(['modifier_group_id', 'code']);
            $table->index(['component_type', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
