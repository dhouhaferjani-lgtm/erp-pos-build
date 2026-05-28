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
        Schema::create('health_claim_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('health_claim_id');
            $table->string('locale', 5);
            $table->text('claim');
            $table->text('disclaimer_text')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('health_claim_id')
                ->references('id')->on('health_claims')
                ->onDelete('cascade');

            // Indexes and constraints
            $table->unique(['health_claim_id', 'locale']);
            $table->index('locale', 'idx_health_claim_translations_locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_claim_translations');
    }
};
