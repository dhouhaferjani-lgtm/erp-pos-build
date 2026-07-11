<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION instrument_events_immutability_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'instrument_events row % cannot be deleted — append-only lifecycle log.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'instrument_events row % cannot be updated — append-only lifecycle log.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'instrument_events cannot be truncated — append-only lifecycle log.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS instrument_events_immutability_update ON instrument_events;
            CREATE TRIGGER instrument_events_immutability_update BEFORE UPDATE ON instrument_events
                FOR EACH ROW EXECUTE FUNCTION instrument_events_immutability_trigger();
            DROP TRIGGER IF EXISTS instrument_events_immutability_delete ON instrument_events;
            CREATE TRIGGER instrument_events_immutability_delete BEFORE DELETE ON instrument_events
                FOR EACH ROW EXECUTE FUNCTION instrument_events_immutability_trigger();
            DROP TRIGGER IF EXISTS instrument_events_immutability_truncate ON instrument_events;
            CREATE TRIGGER instrument_events_immutability_truncate BEFORE TRUNCATE ON instrument_events
                FOR EACH STATEMENT EXECUTE FUNCTION instrument_events_immutability_trigger();
        SQL);

        $appRole = config('database.connections.pgsql.username');
        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            DB::statement('REVOKE TRUNCATE ON instrument_events FROM "'.$appRole.'"');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS instrument_events_immutability_truncate ON instrument_events;
            DROP TRIGGER IF EXISTS instrument_events_immutability_delete ON instrument_events;
            DROP TRIGGER IF EXISTS instrument_events_immutability_update ON instrument_events;
            DROP FUNCTION IF EXISTS instrument_events_immutability_trigger();
        SQL);

        $appRole = config('database.connections.pgsql.username');
        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            DB::statement('GRANT TRUNCATE ON instrument_events TO "'.$appRole.'"');
        }
    }
};
