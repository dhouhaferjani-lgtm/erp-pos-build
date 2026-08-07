<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX audit_events_impersonation_mirror_unique
            ON audit_events (impersonation_event_id)
            WHERE event_type LIKE 'support_access.%'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_events_impersonation_mirror_unique');
    }
};
