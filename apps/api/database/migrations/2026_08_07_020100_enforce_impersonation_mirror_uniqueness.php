<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX admin_audit_logs_impersonation_mirror_unique
            ON admin_audit_logs (impersonation_event_id)
            WHERE entity_type IN ('impersonation_session', 'impersonation_grant')
              AND action LIKE 'impersonation_%'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admin_audit_logs_impersonation_mirror_unique');
    }
};
