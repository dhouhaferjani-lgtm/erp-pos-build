<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fiscal_event_quarantine', 'chain_context')) {
            Schema::table('fiscal_event_quarantine', function (Blueprint $table): void {
                $table->string('chain_context', 32)->default('operational')->after('business_date');
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS fiscal_event_quarantine_unresolved_idx');
        DB::statement(<<<'SQL'
            CREATE INDEX fiscal_event_quarantine_unresolved_idx
                ON fiscal_event_quarantine (tenant_id, terminal_id, chain_context, claimed_sequence_number)
                WHERE resolved_at IS NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS fiscal_event_quarantine_unresolved_idx');
            DB::statement(<<<'SQL'
                CREATE INDEX fiscal_event_quarantine_unresolved_idx
                    ON fiscal_event_quarantine (tenant_id, terminal_id, claimed_sequence_number)
                    WHERE resolved_at IS NULL
            SQL);
        }

        if (Schema::hasColumn('fiscal_event_quarantine', 'chain_context')) {
            Schema::table('fiscal_event_quarantine', function (Blueprint $table): void {
                $table->dropColumn('chain_context');
            });
        }
    }
};
