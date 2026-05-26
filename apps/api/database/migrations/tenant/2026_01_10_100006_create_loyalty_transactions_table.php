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
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relation
            $table->uuid('enrollment_id');

            // Transaction type
            $table->string('transaction_type', 20); // earn, redeem, adjust, expire, transfer_in, transfer_out, bonus, refund

            // Amount
            $table->decimal('amount', 15, 2); // Always positive
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);

            // Source references
            $table->uuid('order_id')->nullable();
            $table->uuid('order_line_id')->nullable();
            $table->uuid('reward_id')->nullable();
            $table->uuid('earning_rule_id')->nullable();

            // Description
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();

            // Audit
            $table->uuid('created_by')->nullable(); // For manual adjustments
            $table->dateTime('created_at');

            // Expiration (for earned points)
            $table->dateTime('expires_at')->nullable();

            // Foreign keys
            $table->foreign('enrollment_id')->references('id')->on('loyalty_enrollments')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('reward_id')->references('id')->on('loyalty_rewards')->nullOnDelete();
            $table->foreign('earning_rule_id')->references('id')->on('earning_rules')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // Indexes
            $table->index(['enrollment_id', 'created_at']);
            $table->index(['enrollment_id', 'transaction_type']);
            $table->index(['order_id']); // For idempotency checks
            $table->index(['created_at']); // For time-series queries
            $table->index(['expires_at']); // For expiration job
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
