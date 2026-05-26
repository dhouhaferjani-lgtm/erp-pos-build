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
        Schema::create('loyalty_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relations
            $table->uuid('program_id');
            $table->uuid('member_id');

            // Balances (cached for performance)
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->decimal('lifetime_earned', 15, 2)->default(0); // Immutable
            $table->decimal('lifetime_redeemed', 15, 2)->default(0); // Immutable

            // Tier
            $table->uuid('current_tier_id')->nullable();
            $table->dateTime('tier_qualified_at')->nullable();

            // Status
            $table->string('status', 20)->default('active'); // active, suspended, opted_out
            $table->dateTime('enrolled_at');
            $table->dateTime('last_transaction_at')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('program_id')->references('id')->on('loyalty_programs')->onDelete('cascade');
            $table->foreign('member_id')->references('id')->on('loyalty_members')->onDelete('cascade');
            $table->foreign('current_tier_id')->references('id')->on('loyalty_tiers')->nullOnDelete();

            // Indexes
            $table->unique(['program_id', 'member_id']);
            $table->index(['member_id', 'status']);
            $table->index(['program_id', 'current_tier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_enrollments');
    }
};
