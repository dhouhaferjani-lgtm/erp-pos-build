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
        if (! Schema::hasColumn('fiscal_events', 'chain_context')) {
            Schema::table('fiscal_events', function (Blueprint $table): void {
                $table->string('chain_context', 32)->default('operational')->after('business_date');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_events DROP CONSTRAINT IF EXISTS fiscal_events_tenant_terminal_sequence_unique');
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_events
                    ADD CONSTRAINT fiscal_events_tenant_terminal_sequence_unique
                    UNIQUE (tenant_id, company_id, terminal_id, chain_context, sequence_number)
            SQL);
            DB::statement('ALTER TABLE fiscal_events DROP CONSTRAINT IF EXISTS fiscal_events_chain_context_allowed');
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_events
                    ADD CONSTRAINT fiscal_events_chain_context_allowed
                    CHECK (chain_context IN ('operational', 'z_session', 'training_operational', 'training_z_session'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_events DROP CONSTRAINT IF EXISTS fiscal_events_tenant_terminal_sequence_unique');
            DB::statement(<<<'SQL'
                ALTER TABLE fiscal_events
                    ADD CONSTRAINT fiscal_events_tenant_terminal_sequence_unique
                    UNIQUE (tenant_id, terminal_id, sequence_number)
            SQL);
            DB::statement('ALTER TABLE fiscal_events DROP CONSTRAINT IF EXISTS fiscal_events_chain_context_allowed');
        }

        if (Schema::hasColumn('fiscal_events', 'chain_context')) {
            Schema::table('fiscal_events', function (Blueprint $table): void {
                $table->dropColumn('chain_context');
            });
        }
    }
};
