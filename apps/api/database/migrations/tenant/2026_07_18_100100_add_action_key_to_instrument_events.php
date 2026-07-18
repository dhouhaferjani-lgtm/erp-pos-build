<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION_KEY_INDEX = 'instrument_events_action_key_uniq';

    private const DIGEST_CHECK = 'instrument_events_action_digest_chk';

    private const SQLITE_INSERT_TRIGGER = 'instrument_events_action_digest_insert_guard';

    private const SQLITE_UPDATE_TRIGGER = 'instrument_events_action_digest_update_guard';

    public function up(): void
    {
        if (! Schema::hasTable('instrument_events')) {
            return;
        }

        if (! Schema::hasColumn('instrument_events', 'action_key')) {
            Schema::table('instrument_events', function (Blueprint $table): void {
                $table->string('action_key', 255)->nullable()->after('event_type');
            });
        }

        if (! Schema::hasColumn('instrument_events', 'semantic_digest')) {
            Schema::table('instrument_events', function (Blueprint $table): void {
                $table->string('semantic_digest', 64)->nullable()->after('action_key');
            });
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON instrument_events (action_key) WHERE action_key IS NOT NULL',
            self::ACTION_KEY_INDEX,
        ));

        $this->addDigestGuard();
    }

    public function down(): void
    {
        if (! Schema::hasTable('instrument_events')) {
            return;
        }

        $this->dropDigestGuard();
        DB::statement('DROP INDEX IF EXISTS '.self::ACTION_KEY_INDEX);

        foreach (['semantic_digest', 'action_key'] as $column) {
            if (Schema::hasColumn('instrument_events', $column)) {
                Schema::table('instrument_events', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addDigestGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(sprintf(<<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM pg_constraint
                        WHERE conname = '%s'
                          AND conrelid = 'instrument_events'::regclass
                    ) THEN
                        ALTER TABLE instrument_events
                            ADD CONSTRAINT %s
                            CHECK (action_key IS NULL OR semantic_digest IS NOT NULL);
                    END IF;
                END
                $$;
                SQL,
                self::DIGEST_CHECK,
                self::DIGEST_CHECK,
            ));

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(sprintf(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS %s
                BEFORE INSERT ON instrument_events
                WHEN NEW.action_key IS NOT NULL AND NEW.semantic_digest IS NULL
                BEGIN
                    SELECT RAISE(ABORT, 'instrument action_key requires semantic_digest');
                END;
                SQL,
                self::SQLITE_INSERT_TRIGGER,
            ));
            DB::unprepared(sprintf(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS %s
                BEFORE UPDATE ON instrument_events
                WHEN NEW.action_key IS NOT NULL AND NEW.semantic_digest IS NULL
                BEGIN
                    SELECT RAISE(ABORT, 'instrument action_key requires semantic_digest');
                END;
                SQL,
                self::SQLITE_UPDATE_TRIGGER,
            ));
        }
    }

    private function dropDigestGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE instrument_events DROP CONSTRAINT IF EXISTS %s',
                self::DIGEST_CHECK,
            ));

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::SQLITE_UPDATE_TRIGGER);
            DB::statement('DROP TRIGGER IF EXISTS '.self::SQLITE_INSERT_TRIGGER);
        }
    }
};
