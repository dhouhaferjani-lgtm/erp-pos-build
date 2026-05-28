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

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS pos_terminals_unique_virtual_admin_per_company
             ON pos_terminals (tenant_id, company_id)
             WHERE type = 'virtual_admin' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS pos_terminals_unique_virtual_admin_per_company');
    }
};
