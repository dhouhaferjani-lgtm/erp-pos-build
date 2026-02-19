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
        Schema::create('member_stamp_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relations
            $table->uuid('card_definition_id');
            $table->uuid('enrollment_id');

            // Progress
            $table->integer('current_stamps')->default(0);

            // Lifecycle
            $table->dateTime('started_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reward_claimed_at')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('card_definition_id')->references('id')->on('stamp_card_definitions')->onDelete('cascade');
            $table->foreign('enrollment_id')->references('id')->on('loyalty_enrollments')->onDelete('cascade');

            // Indexes
            $table->index(['enrollment_id', 'completed_at']);
            $table->index(['card_definition_id', 'started_at']);
            $table->index('expires_at'); // For expiration job
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_stamp_cards');
    }
};
