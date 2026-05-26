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
        Schema::create('certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 100)->comment('organic, halal, kosher, vegan, gmp, iso, etc.');
            $table->string('slug', 255)->unique();
            $table->string('certifying_body', 255)->nullable()->comment('e.g., Ecocert, Halal Monitoring Committee');
            $table->string('logo_url', 500)->nullable();
            $table->string('verification_url', 500)->nullable()->comment('URL to verify certification');
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('type', 'idx_certifications_type');
            $table->index('slug', 'idx_certifications_slug');
            $table->index('is_active', 'idx_certifications_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certifications');
    }
};
