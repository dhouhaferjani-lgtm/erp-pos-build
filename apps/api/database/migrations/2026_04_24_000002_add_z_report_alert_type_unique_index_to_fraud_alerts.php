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

        // Dedup-first pattern to ensure migration is roll-forward safe even if prod
        // has duplicate fraud_alerts rows from before the unique constraint was introduced.
        // Mirrors the sibling pattern in 2026_04_23_000001_add_shift_id_unique_to_pos_z_reports.
        DB::statement(
            <<<'SQL'
            DELETE FROM fraud_alerts
            WHERE id IN (
                SELECT id FROM (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY metadata->>'z_report_id', alert_type
                               ORDER BY created_at, id
                           ) AS rn
                    FROM fraud_alerts
                    WHERE metadata->>'z_report_id' IS NOT NULL
                ) ranked
                WHERE rn > 1
            )
            SQL
        );

        DB::statement(
            <<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS fraud_alerts_z_report_alert_type_unique
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
