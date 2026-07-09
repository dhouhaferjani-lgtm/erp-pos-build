<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 22 (cutover) — forbid any direct `payment_repositories.balance` write
 * outside the treasury movement port (spec §5).
 *
 * `balance` is port-managed: the single writer is TreasuryMovementService, which
 * issues `SET LOCAL app.treasury_movement_port = 'on'` inside its transaction
 * (before its idempotency savepoint, MED-10). This BEFORE-UPDATE trigger rejects
 * any UPDATE that changes `balance` unless that session GUC is set to 'on'.
 *
 * The freeze/unfreeze path (Task 13) is NOT affected: it updates only
 * frozen_at/frozen_reason, so `NEW.balance IS DISTINCT FROM OLD.balance` is false
 * and the trigger skips it. INSERTs are unaffected (BEFORE UPDATE only), so
 * repository creation and unguarded seeding still work.
 *
 * pgsql-only (a GUC/plpgsql trigger); a no-op on the sqlite test driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_direct_balance_write() RETURNS trigger AS $$
            BEGIN
                IF NEW.balance IS DISTINCT FROM OLD.balance
                   AND current_setting('app.treasury_movement_port', true) IS DISTINCT FROM 'on' THEN
                    RAISE EXCEPTION 'payment_repositories.balance may only be changed via TreasuryMovementService (spec §5).'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION forbid_direct_balance_write() IS
                'Spec §5 / Task 22: payment_repositories.balance is port-managed — rejects any UPDATE that changes balance unless app.treasury_movement_port = ''on'' (set by TreasuryMovementService).';
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER forbid_direct_balance_write_trg BEFORE UPDATE ON payment_repositories
                FOR EACH ROW EXECUTE FUNCTION forbid_direct_balance_write();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS forbid_direct_balance_write_trg ON payment_repositories;
            DROP FUNCTION IF EXISTS forbid_direct_balance_write();
        SQL);
    }
};
