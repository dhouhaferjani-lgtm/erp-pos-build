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
        Schema::create('ingredient_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ingredient_id');
            $table->string('locale', 5)->comment('en, fr, ar, zh, etc.');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('ingredient_id')
                ->references('id')->on('ingredients')
                ->onDelete('cascade');

            // Indexes and constraints
            $table->unique(['ingredient_id', 'locale']);
            $table->index('locale', 'idx_ingredient_translations_locale');
            $table->index('ingredient_id', 'idx_ingredient_translations_ingredient');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_translations');
    }
};
