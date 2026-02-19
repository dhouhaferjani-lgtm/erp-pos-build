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
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relation
            $table->uuid('program_id');

            // Reward identity
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('reward_type', 30); // free_item, discount_amount, discount_percent, choice, credit, external

            // Cost
            $table->decimal('points_cost', 15, 2);

            // Value
            $table->decimal('reward_value', 15, 2)->nullable(); // For discounts/credit

            // Qualifying items (JSONB with DTO)
            $table->jsonb('qualifying_items')->nullable();

            // Restrictions
            $table->decimal('max_discount', 15, 2)->nullable();
            $table->decimal('min_order_value', 15, 2)->nullable();
            $table->json('tier_ids')->nullable(); // Restrict to specific tiers

            // Status
            $table->boolean('is_active')->default(true);

            // Inventory
            $table->integer('quantity_available')->nullable();
            $table->integer('quantity_per_member')->nullable();

            // Validity
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('program_id')->references('id')->on('loyalty_programs')->onDelete('cascade');

            // Indexes
            $table->index(['program_id', 'is_active']);
            $table->index(['program_id', 'reward_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');
    }
};
