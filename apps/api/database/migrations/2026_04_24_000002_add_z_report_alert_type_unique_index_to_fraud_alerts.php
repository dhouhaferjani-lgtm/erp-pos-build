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
            <<<'SQL'
            CREATE UNIQUE INDEX fraud_alerts_z_report_alert_type_unique
            ON fraud_alerts ((metadata->>'z_report_id'), alert_type)
            WHERE metadata->>'z_report_id' IS NOT NULL
            SQL
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS fraud_alerts_z_report_alert_type_unique');
    }
};
