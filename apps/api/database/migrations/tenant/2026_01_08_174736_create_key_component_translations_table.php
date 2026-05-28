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
        Schema::create('key_component_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('component_id');
            $table->string('locale', 5);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('component_id')
                ->references('id')->on('product_key_components')
                ->onDelete('cascade');

            // Indexes and constraints
            $table->unique(['component_id', 'locale']);
            $table->index('locale', 'idx_key_component_translations_locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('key_component_translations');
    }
};
