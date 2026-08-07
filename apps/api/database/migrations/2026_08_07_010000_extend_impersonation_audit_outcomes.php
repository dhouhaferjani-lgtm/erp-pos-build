<?php

declare(strict_types=1);

use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $values = implode(', ', array_map(
            static fn (AuditOutcome $case): string => DB::getPdo()->quote($case->value),
            AuditOutcome::cases(),
        ));
        DB::statement('ALTER TABLE impersonation_session_events DROP CONSTRAINT IF EXISTS impersonation_session_events_outcome_check');
        DB::statement("ALTER TABLE impersonation_session_events ADD CONSTRAINT impersonation_session_events_outcome_check CHECK (outcome IN ({$values}))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $values = implode(', ', array_map(
            static fn (AuditOutcome $case): string => DB::getPdo()->quote($case->value),
            array_filter(AuditOutcome::cases(), static fn (AuditOutcome $case): bool => $case !== AuditOutcome::Observed),
        ));
        DB::statement('ALTER TABLE impersonation_session_events DROP CONSTRAINT IF EXISTS impersonation_session_events_outcome_check');
        DB::statement("ALTER TABLE impersonation_session_events ADD CONSTRAINT impersonation_session_events_outcome_check CHECK (outcome IN ({$values}))");
    }
};
