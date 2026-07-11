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
 * (before its idempotency savepoint, MED-10). This BEFORE INSERT-OR-UPDATE trigger
 * enforces two invariants unless that session GUC is set to 'on':
 *   - UPDATE: rejects any UPDATE that CHANGES `balance`
 *     (`NEW.balance IS DISTINCT FROM OLD.balance`).
 *   - INSERT: rejects any INSERT that is BORN with a non-zero `balance`
 *     (`NEW.balance IS DISTINCT FROM 0`). A repository must be created at balance
 *     0 — a non-zero opening balance must arrive via a port `opening_balance`
 *     movement (which also lays down the backing ledger leg so the cached balance
 *     reconciles). Closing the INSERT escape (cutover-hardening Fix 1): without it
 *     a `forceCreate(['balance' => '5000'])` would mint a cached balance with NO
 *     backing movement, violating the invariant at row birth.
 *
 * The freeze/unfreeze path (Task 13) is NOT affected: it updates only
 * frozen_at/frozen_reason, so `NEW.balance IS DISTINCT FROM OLD.balance` is false
 * and the trigger skips it. Normal repository creation inserts balance 0 (model
 * default), so the INSERT branch skips it too.
 *
 * pgsql-only (a GUC/plpgsql trigger); a no-op on the sqlite test driver.
 * Re-runnable (Fix 4): `CREATE OR REPLACE FUNCTION` + `DROP TRIGGER IF EXISTS`
 * before `CREATE TRIGGER`, so a partial/re-run deploy does not fail on a duplicate.
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
                IF TG_OP = 'INSERT' THEN
                    IF NEW.balance IS DISTINCT FROM 0
                       AND current_setting('app.treasury_movement_port', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'payment_repositories must be created at balance 0; a non-zero opening balance must be recorded via TreasuryMovementService (spec §5).'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                ELSE
                    IF NEW.balance IS DISTINCT FROM OLD.balance
                       AND current_setting('app.treasury_movement_port', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'payment_repositories.balance may only be changed via TreasuryMovementService (spec §5).'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION forbid_direct_balance_write() IS
                'Spec §5 / Task 22: payment_repositories.balance is port-managed — rejects any UPDATE that changes balance, and any INSERT born with a non-zero balance, unless app.treasury_movement_port = ''on'' (set by TreasuryMovementService).';
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS forbid_direct_balance_write_trg ON payment_repositories;
            CREATE TRIGGER forbid_direct_balance_write_trg BEFORE INSERT OR UPDATE ON payment_repositories
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
