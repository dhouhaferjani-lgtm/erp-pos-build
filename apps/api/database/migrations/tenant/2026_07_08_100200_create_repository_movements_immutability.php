<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 3 — `repository_movements` immutability trigger (append-only).
 *
 * Spec §4: unlike `fiscal_events`, there is no allowed-mutation whitelist —
 * `repository_movements` rows are wholly immutable once inserted. The
 * `recorded_while_frozen` flag (and every other column) is set at INSERT
 * time and never mutated; corrections are compensating movements, not
 * updates.
 *
 * Modelled on `2026_05_14_100002_create_fiscal_events_immutability.php`
 * (pgsql-guarded trigger function + REVOKE TRUNCATE pattern), but pure-reject:
 * no allowed-column whitelist, no named state transitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION repository_movements_immutability_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'repository_movements row % cannot be deleted — append-only ledger (spec §4). Corrections are compensating movements.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'repository_movements row % cannot be updated — append-only ledger (spec §4). Corrections are compensating movements.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'repository_movements cannot be truncated — append-only ledger (spec §4).'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION repository_movements_immutability_trigger() IS
                'Spec §4: repository_movements immutability — pure-reject, no allowed-column whitelist (unlike fiscal_events).';
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER repository_movements_immutability_update BEFORE UPDATE ON repository_movements
                FOR EACH ROW EXECUTE FUNCTION repository_movements_immutability_trigger();
            CREATE TRIGGER repository_movements_immutability_delete BEFORE DELETE ON repository_movements
                FOR EACH ROW EXECUTE FUNCTION repository_movements_immutability_trigger();
            CREATE TRIGGER repository_movements_immutability_truncate BEFORE TRUNCATE ON repository_movements
                FOR EACH STATEMENT EXECUTE FUNCTION repository_movements_immutability_trigger();
        SQL);

        $appRole = config('database.connections.pgsql.username');
        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            DB::statement('REVOKE TRUNCATE ON repository_movements FROM "'.$appRole.'"');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS repository_movements_immutability_truncate ON repository_movements;
            DROP TRIGGER IF EXISTS repository_movements_immutability_delete ON repository_movements;
            DROP TRIGGER IF EXISTS repository_movements_immutability_update ON repository_movements;
            DROP FUNCTION IF EXISTS repository_movements_immutability_trigger();
        SQL);

        $appRole = config('database.connections.pgsql.username');
        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            DB::statement('GRANT TRUNCATE ON repository_movements TO "'.$appRole.'"');
        }
    }
};
