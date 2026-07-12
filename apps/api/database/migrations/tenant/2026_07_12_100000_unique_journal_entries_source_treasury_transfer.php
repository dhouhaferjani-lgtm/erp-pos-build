<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One POSTED journal entry per treasury transfer group (spec Rev 2 §5.3).
 * Status-scoped deliberately: the replay path re-inserts a Draft with the
 * same (source_type, source_id) on every attempt and cleans it up after
 * transfer() resolves — an unscoped index would 23505 at the draft INSERT,
 * outside transfer()'s catch, breaking idempotent replay (review L1-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // NO driver guard (plan-review F3): the procurement exemplar runs
        // unconditionally and sqlite supports partial indexes — guarding
        // would silently remove index coverage from the sqlite fast loop.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS journal_entries_treasury_transfer_source_unique
            ON journal_entries (source_type, source_id)
            WHERE source_type = 'treasury_transfer' AND status = 'posted'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS journal_entries_treasury_transfer_source_unique');
    }
};
