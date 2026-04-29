<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a PostgreSQL CHECK constraint on voucher_ledger.event enforcing the
 * 8 canonical VoucherEvent enum values.
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

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE voucher_ledger DROP CONSTRAINT IF EXISTS voucher_ledger_event_check;');
        }
    }
};
