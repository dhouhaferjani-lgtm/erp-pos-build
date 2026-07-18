<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION_KEY_INDEX = 'instrument_events_action_key_uniq';

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
    }

    public function down(): void
    {
        if (! Schema::hasTable('instrument_events')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::ACTION_KEY_INDEX);

        foreach (['semantic_digest', 'action_key'] as $column) {
            if (Schema::hasColumn('instrument_events', $column)) {
                Schema::table('instrument_events', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
