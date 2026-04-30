<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the voucher_ledger.event CHECK constraint to allow the new
 * 'expiry_extended' VoucherEvent enum case.
 *
 * Added by Codex review m2 (2026-04-30): VoucherController::extendExpiry()
 * now writes a metadata-only ledger row symmetrical with the Transferred
 * event so the administrative state change is reconstructible from the
 * ledger projection.
 *
 * SQLite does not enforce CHECK constraints by default, so this migration
 * is safe on both platforms; the constraint is a production-only guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE voucher_ledger
                    DROP CONSTRAINT IF EXISTS voucher_ledger_event_check;

                ALTER TABLE voucher_ledger
                    ADD CONSTRAINT voucher_ledger_event_check
                    CHECK (event IN (
                        'issued',
                        'redeemed',
                        'partially_redeemed',
                        'expired',
                        'voided',
                        'reversed',
                        'transferred',
                        'rounding_adjustment',
                        'expiry_extended'
                    ));
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE voucher_ledger
                    DROP CONSTRAINT IF EXISTS voucher_ledger_event_check;

                ALTER TABLE voucher_ledger
                    ADD CONSTRAINT voucher_ledger_event_check
                    CHECK (event IN (
                        'issued',
                        'redeemed',
                        'partially_redeemed',
                        'expired',
                        'voided',
                        'reversed',
                        'transferred',
                        'rounding_adjustment'
                    ));
            SQL);
        }
    }
};
