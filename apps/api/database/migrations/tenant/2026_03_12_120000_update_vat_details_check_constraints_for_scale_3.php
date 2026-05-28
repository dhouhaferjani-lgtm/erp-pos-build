<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix pos_receipt_vat_details check constraints for multi-scale currencies.
 *
 * The original `vat_calc` constraint hardcoded `round(..., 2)` which breaks for
 * currencies with 3 decimal places (TND, LYD, BHD, etc.).
 *
 * The rigid vat_calc constraint is removed because:
 * - Rounding at different scales (2 vs 3) produces different results
 * - Existing data was stored at scale 2, new data uses scale 3
 * - The `gross_calc` constraint (gross = net + vat) already validates arithmetic
 * - Fiscal integrity is guaranteed by the hash chain, not DB constraints
 *
 * The `amounts` (>= 0) and `gross_calc` constraints are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Drop the rigid vat_calc constraint that breaks with scale 3 currencies
        DB::statement('ALTER TABLE pos_receipt_vat_details DROP CONSTRAINT IF EXISTS pos_receipt_vat_details_vat_calc');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore original scale-2 constraint (will fail if scale-3 data exists)
        DB::statement('ALTER TABLE pos_receipt_vat_details ADD CONSTRAINT pos_receipt_vat_details_vat_calc CHECK (
            vat_amount = round((net_amount * tax_rate / 100)::numeric, 2)
        )');
    }
};
