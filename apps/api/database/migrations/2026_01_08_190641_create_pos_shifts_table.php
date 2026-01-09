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
     * Creates pos_shifts table for cashier work session tracking.
     * Critical for cash drawer management and end-of-day reporting.
     */
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Terminal and Cashier
            $table->foreignUuid('terminal_id')
                ->constrained('pos_terminals')
                ->restrictOnDelete();
            $table->foreignUuid('cashier_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Shift Number (sequential, never resets)
            $table->integer('shift_number');

            // Cash Balances
            $table->decimal('opening_cash', 12, 2);
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('actual_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();

            // Status
            $table->string('status', 20)->default('OPEN');

            // Timestamps
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // Notes
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('terminal_id');
            $table->index('cashier_id');
            $table->index('status');
            $table->index(['terminal_id', 'opened_at']);
            $table->index(['terminal_id', 'shift_number']);

        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Partial unique index: only one OPEN shift per terminal
            DB::statement('CREATE UNIQUE INDEX pos_shifts_one_open_per_terminal ON pos_shifts (terminal_id) WHERE status = \'OPEN\'');

            // Check Constraints
            DB::statement('ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_positive_amounts CHECK (
                opening_cash >= 0 AND
                (expected_cash IS NULL OR expected_cash >= 0) AND
                (actual_cash IS NULL OR actual_cash >= 0)
            )');
            DB::statement('ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_variance_calc CHECK (
                (variance IS NULL AND actual_cash IS NULL) OR
                (variance = actual_cash - expected_cash)
            )');
            DB::statement('ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_status CHECK (
                status IN (\'OPEN\', \'CLOSED\')
            )');
            DB::statement('ALTER TABLE pos_shifts ADD CONSTRAINT pos_shifts_closed_logic CHECK (
                (status = \'OPEN\' AND closed_at IS NULL AND closed_by IS NULL) OR
                (status = \'CLOSED\' AND closed_at IS NOT NULL AND closed_by IS NOT NULL)
            )');

            // Table Comments
            DB::statement('COMMENT ON TABLE pos_shifts IS \'Cashier work sessions with cash drawer tracking\'');
            DB::statement('COMMENT ON COLUMN pos_shifts.shift_number IS \'Sequential shift number (never resets)\'');
            DB::statement('COMMENT ON COLUMN pos_shifts.opening_cash IS \'Cash declared at shift open\'');
            DB::statement('COMMENT ON COLUMN pos_shifts.expected_cash IS \'Calculated expected cash at shift close (opening + sales - payouts + deposits)\'');
            DB::statement('COMMENT ON COLUMN pos_shifts.actual_cash IS \'Counted cash at shift close\'');
            DB::statement('COMMENT ON COLUMN pos_shifts.variance IS \'Difference: actual - expected (positive = overage, negative = shortage)\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};
