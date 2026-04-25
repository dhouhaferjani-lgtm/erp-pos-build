<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `tolerance_writeoff` to pos_receipts.
 *
 * Sibling to `change_due` (added in 2026_04_23_100000). NULL means no
 * tolerance was applied to this receipt; non-null means the cash payment
 * was short by this amount and was written off to GL 658 (PaymentTolerance v2).
 *
 * Read by PaymentToleranceQueryService for shift-level reporting.
 * Populated by the POS short-pay flow (Phase 4) when a cashier accepts
 * the cash-rounding tolerance prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->decimal('tolerance_writeoff', 15, 3)
                ->nullable()
                ->after('change_due');
        });

        // PostgreSQL supports COMMENT ON COLUMN; SQLite (test driver) ignores column comments.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN pos_receipts.tolerance_writeoff IS 'Amount written off to GL 658 for cash-sale tolerance. NULL for non-tolerance receipts.'");
        }
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table): void {
            $table->dropColumn('tolerance_writeoff');
        });
    }
};
