<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add tolerance write-off aggregates to pos_shifts.
 *
 * - `tolerance_writeoff_total` — running sum of cash-tolerance write-offs
 *   posted to GL 658 during the shift (PaymentTolerance v2).
 * - `tolerance_writeoff_count` — number of receipts in the shift that had
 *   a non-zero tolerance write-off applied.
 *
 * Both default to 0 so existing shifts remain valid post-migration with no
 * backfill required. Mutated in-place by Shift::applyToleranceWriteoff()
 * inside ReceiptPaymentService (Phase 4), which holds a pessimistic lock
 * on the shift row — this migration adds storage only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->decimal('tolerance_writeoff_total', 15, 3)
                ->default(0)
                ->after('variance');
            $table->integer('tolerance_writeoff_count')
                ->default(0)
                ->after('tolerance_writeoff_total');
        });

        // PostgreSQL supports COMMENT ON COLUMN; SQLite (test driver) ignores column comments.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON COLUMN pos_shifts.tolerance_writeoff_total IS 'Running sum of cash-tolerance write-offs (GL 658) for the shift. Defaults 0.'");
            DB::statement("COMMENT ON COLUMN pos_shifts.tolerance_writeoff_count IS 'Number of receipts in the shift with a non-zero tolerance write-off. Defaults 0.'");
        }
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->dropColumn(['tolerance_writeoff_total', 'tolerance_writeoff_count']);
        });
    }
};
