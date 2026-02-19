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
     * Adds transaction-level discount tracking to POS receipts.
     * This supports whole-receipt discounts applied after line items are calculated.
     */
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            // Transaction Discount Tracking
            $table->decimal('discount_amount', 12, 2)->default(0.00)
                ->after('tax_amount')
                ->comment('Transaction-level discount applied to entire receipt');

            $table->string('discount_reason', 255)->nullable()
                ->after('discount_amount')
                ->comment('Reason for discount (required for high discounts)');

            $table->decimal('discount_authorized_by', 5, 2)->nullable()
                ->after('discount_reason')
                ->comment('Cashier max_discount_percent at transaction time (audit trail)');
        });

        // PostgreSQL-specific constraints (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop the old total constraint
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');

            // Add new total constraint that includes discount
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount - discount_amount)');

            // Add constraint for discount amount
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_discount_positive CHECK (discount_amount >= 0)');

            // Add column comments
            DB::statement('COMMENT ON COLUMN pos_receipts.discount_amount IS \'Transaction-level discount applied to entire receipt (NF525 tracked)\'');
            DB::statement('COMMENT ON COLUMN pos_receipts.discount_authorized_by IS \'Snapshot of cashier max_discount_percent at transaction time for audit compliance\'');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // PostgreSQL-specific constraint cleanup (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop the constraints we added
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_discount_positive');

            // Restore original total constraint
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount)');
        }

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'discount_amount',
                'discount_reason',
                'discount_authorized_by',
            ]);
        });
    }
};
