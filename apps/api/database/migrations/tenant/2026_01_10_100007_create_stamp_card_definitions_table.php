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
        Schema::create('stamp_card_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relation
            $table->uuid('program_id');

            // Card identity
            $table->string('name', 100);
            $table->integer('stamps_required');
            $table->integer('stamps_per_item')->default(1);

            // Qualifying items (JSONB with DTO)
            $table->jsonb('qualifying_items');

            // Reward
            $table->uuid('reward_id');

            // Limits
            $table->integer('max_active_cards')->nullable();
            $table->integer('expiry_days')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('program_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->foreign('reward_id')->references('id')->on('loyalty_rewards')->onDelete('restrict');

            // Indexes
            $table->index('program_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stamp_card_definitions');
    }
};
