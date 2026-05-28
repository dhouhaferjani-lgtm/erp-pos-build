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
        Schema::create('certification_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('certification_id');
            $table->string('locale', 5);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('certification_id')
                ->references('id')->on('certifications')
                ->onDelete('cascade');

            // Indexes and constraints
            $table->unique(['certification_id', 'locale']);
            $table->index('locale', 'idx_certification_translations_locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certification_translations');
    }
};
