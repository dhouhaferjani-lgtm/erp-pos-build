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
     * Creates pos_cash_drawer_operations table for complete audit trail of cash movements.
     * Immutable after creation - provides forensic audit capability.
     */
    public function up(): void
    {
        Schema::create('pos_cash_drawer_operations', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Parent Shift
            $table->foreignUuid('shift_id')
                ->constrained('pos_shifts')
                ->cascadeOnDelete();

            // Operation Type
            $table->string('operation_type', 20);

            // Amount (positive for additions, negative for removals)
            $table->decimal('amount', 12, 2);

            // User who performed the operation
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Reason/Description
            $table->text('reason')->nullable();

            // Receipt Reference (for SALE and REFUND operations)
            $table->foreignUuid('receipt_id')
                ->nullable()
                ->constrained('pos_receipts')
                ->restrictOnDelete();

            // Timestamp (immutable after creation)
            $table->timestamp('created_at');

            // Indexes
            $table->index('shift_id');
            $table->index('operation_type');
            $table->index('user_id');
            $table->index('receipt_id');
            $table->index(['shift_id', 'created_at']);
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_cash_drawer_operations ADD CONSTRAINT pos_cash_drawer_operations_type CHECK (
                operation_type IN (\'OPENING\', \'SALE\', \'REFUND\', \'DEPOSIT\', \'PAYOUT\', \'CLOSING\')
            )');
            DB::statement('ALTER TABLE pos_cash_drawer_operations ADD CONSTRAINT pos_cash_drawer_operations_receipt_logic CHECK (
                (operation_type IN (\'SALE\', \'REFUND\') AND receipt_id IS NOT NULL) OR
                (operation_type NOT IN (\'SALE\', \'REFUND\') AND receipt_id IS NULL)
            )');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_cash_drawer_operations IS \'Immutable audit trail of all cash drawer movements during shift\'');
            DB::statement('COMMENT ON COLUMN pos_cash_drawer_operations.operation_type IS \'OPENING: Initial cash | SALE: Cash from sale | REFUND: Cash refunded | DEPOSIT: Cash moved to safe | PAYOUT: Petty cash out | CLOSING: Final balance\'');
            DB::statement('COMMENT ON COLUMN pos_cash_drawer_operations.amount IS \'Amount of cash movement (positive = addition, negative = removal)\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_cash_drawer_operations');
    }
};
