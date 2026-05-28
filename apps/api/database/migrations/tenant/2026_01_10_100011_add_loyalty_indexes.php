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
        // Add composite indexes for common queries
        Schema::table('loyalty_enrollments', function (Blueprint $table): void {
            $table->index(['status', 'current_balance'], 'loyalty_enrollments_status_balance_index');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            // For idempotency: check if transaction exists for order_id + line_id
            $table->index(['order_id', 'order_line_id'], 'loyalty_transactions_order_line_idempotency');

            // For expiration queries
            $table->index(['expires_at', 'transaction_type'], 'loyalty_transactions_expires_type_index');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->index('loyalty_member_id', 'documents_loyalty_member_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loyalty_enrollments', function (Blueprint $table): void {
            $table->dropIndex('loyalty_enrollments_status_balance_index');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table): void {
            $table->dropIndex('loyalty_transactions_order_line_idempotency');
            $table->dropIndex('loyalty_transactions_expires_type_index');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_loyalty_member_id_index');
        });
    }
};
