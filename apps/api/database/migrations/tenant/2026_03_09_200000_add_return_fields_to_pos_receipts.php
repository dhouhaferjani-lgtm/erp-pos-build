<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add return/refund support fields to POS receipts.
     *
     * Adds receipt_type (sale/return), original_receipt_id (FK for returns),
     * and return_reason to support partial returns that create new negative receipts.
     *
     * Also relaxes non-negative constraints on VAT details to allow negative amounts
     * in return receipts (which have negative totals by design).
     */
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->string('receipt_type', 20)->default('sale')
                ->after('receipt_number')
                ->comment('Receipt type: sale or return');

            $table->foreignUuid('original_receipt_id')
                ->nullable()
                ->after('receipt_type')
                ->constrained('pos_receipts')
                ->restrictOnDelete();

            $table->string('return_reason', 50)->nullable()
                ->after('original_receipt_id')
                ->comment('Return reason enum value (defective, wrong_item, customer_changed_mind, other)');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Drop old totals constraint and re-add (same formula, but now total can be negative for returns)
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount - discount_amount)');

            // Return receipts must reference an original receipt
            DB::statement("ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_return_logic CHECK (
                (receipt_type = 'sale') OR
                (receipt_type = 'return' AND original_receipt_id IS NOT NULL AND return_reason IS NOT NULL)
            )");

            // Relax VAT detail constraints to allow negative amounts for return receipts.
            // The amounts constraint (>= 0) blocks return receipts which have negative net/vat/gross.
            DB::statement('ALTER TABLE pos_receipt_vat_details DROP CONSTRAINT IF EXISTS pos_receipt_vat_details_amounts');
            DB::statement('ALTER TABLE pos_receipt_vat_details DROP CONSTRAINT IF EXISTS pos_receipt_vat_details_gross_calc');
            DB::statement('ALTER TABLE pos_receipt_vat_details DROP CONSTRAINT IF EXISTS pos_receipt_vat_details_vat_calc');

            // Re-add structural constraints without the non-negative requirement
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_gross_calc CHECK (
                gross_amount = net_amount + vat_amount
            )');
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_vat_calc CHECK (
                vat_amount = round((net_amount * tax_rate / 100)::numeric, 2)
            )');

            // Relax receipt lines constraints to allow negative quantities and totals for returns.
            // Return receipt lines have negative quantities and line_totals by design.
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_quantity');
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_positive_amounts');
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_line_total_calc');

            // Re-add quantity constraint: allow negative for returns (quantity != 0)
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_quantity CHECK (quantity != 0)');

            // Re-add line_total calculation constraint that works for both positive and negative quantities
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_amounts CHECK (
                unit_price >= 0 AND
                discount_amount >= 0
            )');

            DB::statement('COMMENT ON COLUMN pos_receipts.receipt_type IS \'Receipt type: sale (positive amounts) or return (negative amounts, references original)\'');
            DB::statement('COMMENT ON COLUMN pos_receipts.original_receipt_id IS \'FK to original receipt for return receipts\'');
            DB::statement('COMMENT ON COLUMN pos_receipts.return_reason IS \'Reason for return (defective, wrong_item, customer_changed_mind, other)\'');

            // Index for finding returns linked to a receipt
            DB::statement('CREATE INDEX idx_pos_receipts_original_receipt ON pos_receipts (original_receipt_id) WHERE original_receipt_id IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_pos_receipts_original_receipt');
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_return_logic');
            DB::statement('ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_totals');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount - discount_amount)');

            // Restore original non-negative VAT constraints
            DB::statement('ALTER TABLE pos_receipt_vat_details DROP CONSTRAINT IF EXISTS pos_receipt_vat_details_gross_calc');
            DB::statement('ALTER TABLE pos_receipt_vat_details DROP CONSTRAINT IF EXISTS pos_receipt_vat_details_vat_calc');
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_amounts CHECK (
                net_amount >= 0 AND
                vat_amount >= 0 AND
                gross_amount >= 0
            )');
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_gross_calc CHECK (
                gross_amount = net_amount + vat_amount
            )');
            DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_vat_calc CHECK (
                vat_amount = round((net_amount * tax_rate / 100)::numeric, 2)
            )');

            // Restore original receipt_lines constraints
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_quantity');
            DB::statement('ALTER TABLE pos_receipt_lines DROP CONSTRAINT IF EXISTS pos_receipt_lines_amounts');
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_quantity CHECK (quantity > 0)');
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_positive_amounts CHECK (
                unit_price >= 0 AND
                line_total >= 0 AND
                tax_amount >= 0 AND
                discount_amount >= 0
            )');
            DB::statement('ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_line_total_calc CHECK (
                line_total = (unit_price * quantity) - discount_amount
            )');
        }

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropForeign(['original_receipt_id']);
            $table->dropColumn(['receipt_type', 'original_receipt_id', 'return_reason']);
        });
    }
};
