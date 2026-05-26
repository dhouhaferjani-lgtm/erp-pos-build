<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates pos_receipt_payments table for payment method breakdown.
     * Tracks how each receipt was paid (cash, card, voucher, etc.) for NF525 compliance.
     */
    public function up(): void
    {
        Schema::create('pos_receipt_payments', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Parent Receipt
            $table->foreignUuid('receipt_id')
                ->constrained('pos_receipts')
                ->cascadeOnDelete();

            // Payment Method Reference
            $table->foreignUuid('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            // Payment Type (snapshot for hash stability)
            $table->string('payment_type', 50);

            // Amount Paid with this Method
            $table->decimal('amount', 12, 2);

            // Payment Instrument Details (if applicable)
            $table->string('voucher_serial', 50)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->string('transaction_reference', 100)->nullable();

            // Authorization Details (for cards)
            $table->string('authorization_code', 50)->nullable();
            $table->timestamp('authorized_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('receipt_id');
            $table->index('payment_method_id');
            $table->index('voucher_serial');
            $table->index('transaction_reference');
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_receipt_payments ADD CONSTRAINT pos_receipt_payments_amount CHECK (amount > 0)');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_receipt_payments IS \'Payment method breakdown per receipt (feeds payment_methods_hash)\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_payments.payment_type IS \'Immutable snapshot: Payment type at time of transaction\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_payments.voucher_serial IS \'Restaurant voucher serial number (if applicable)\'');
            DB::statement('COMMENT ON COLUMN pos_receipt_payments.card_last_four IS \'Last 4 digits of card (if card payment)\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_payments');
    }
};
