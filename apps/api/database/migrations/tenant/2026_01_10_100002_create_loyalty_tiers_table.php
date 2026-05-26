<?php

declare(strict_types=1);

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
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relation
            $table->uuid('program_id');

            // Tier identity
            $table->string('name', 50);
            $table->integer('level'); // 1 = lowest
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable();

            // Qualification
            $table->string('qualification_type', 20); // spend, points_earned, visits, manual
            $table->decimal('qualification_threshold', 15, 2);
            $table->integer('qualification_period_months')->nullable(); // Rolling period

            // Benefits
            $table->decimal('earning_multiplier', 5, 2)->default(1.00); // 1.5 = 50% bonus
            $table->jsonb('benefits')->nullable(); // Additional tier benefits (DTO)

            $table->timestamps();

            // Foreign keys
            $table->foreign('program_id')->references('id')->on('loyalty_programs')->onDelete('cascade');

            // Indexes
            $table->unique(['program_id', 'level']);
            $table->index(['program_id', 'qualification_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
