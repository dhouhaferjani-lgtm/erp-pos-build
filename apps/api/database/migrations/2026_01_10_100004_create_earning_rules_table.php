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
        Schema::create('earning_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relation
            $table->uuid('program_id');

            // Rule identity
            $table->string('name', 100);
            $table->string('rule_type', 20); // spend, item, category, quantity, visit, threshold, time
            $table->integer('priority')->default(1); // Lower = executed first

            // Status
            $table->boolean('is_active')->default(true);

            // Conditions (JSONB with DTO)
            $table->jsonb('conditions');

            // Reward configuration
            $table->decimal('reward_value', 15, 4);
            $table->string('reward_type', 20); // fixed, multiplier, percentage

            // Validity
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();

            // Limits
            $table->decimal('max_earn_per_transaction', 15, 2)->nullable();
            $table->decimal('max_earn_per_day', 15, 2)->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('program_id')->references('id')->on('loyalty_programs')->onDelete('cascade');

            // Indexes
            $table->index(['program_id', 'is_active', 'priority']);
            $table->index(['program_id', 'rule_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('earning_rules');
    }
};
