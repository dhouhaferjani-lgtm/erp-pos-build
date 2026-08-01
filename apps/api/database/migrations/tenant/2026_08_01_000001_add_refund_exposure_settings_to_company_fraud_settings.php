<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\CompanyFraudSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lane C M2/M3 — refund-exposure policy settings.
 *
 * M2 (offline refund velocity ceiling) and M3 (large refunds require the
 * device online) ship as TENANT SETTINGS with seeded best-practice defaults
 * (owner ruling 2026-08-01). They ride the EXISTING `company_fraud_settings`
 * → `/api/v1/pos/fraud-settings` → device `company_fraud_settings_cache`
 * channel — the same one the offline EOD cash-variance thresholds use — so
 * no new sync channel is introduced.
 *
 * SELF-GUARDING (CLAUDE.md rule: an `origin/dev` push auto-runs
 * `tenants:migrate` on staging, so a migration must be safe to (re-)run on a
 * tenant DB in any state):
 *   - the whole body no-ops when `company_fraud_settings` does not exist yet
 *     (a brand-new tenant whose create-table migration has not run in this
 *     batch ordering);
 *   - each column is added only when absent;
 *   - the CHECK constraints are dropped-if-exists before being (re-)added;
 *   - the backfill is an idempotent UPDATE of NULL/invalid rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_fraud_settings')) {
            return;
        }

        $defaults = CompanyFraudSettings::getDefaults();

        Schema::table('company_fraud_settings', function (Blueprint $table) use ($defaults): void {
            if (! Schema::hasColumn('company_fraud_settings', 'offline_refund_count_ceiling')) {
                $table->unsignedSmallInteger('offline_refund_count_ceiling')
                    ->default((int) $defaults['offline_refund_count_ceiling']);
            }

            if (! Schema::hasColumn('company_fraud_settings', 'offline_refund_value_ceiling')) {
                $table->decimal('offline_refund_value_ceiling', 12, 4)
                    ->default($defaults['offline_refund_value_ceiling']);
            }

            if (! Schema::hasColumn('company_fraud_settings', 'online_required_refund_threshold')) {
                $table->decimal('online_required_refund_threshold', 12, 4)
                    ->default($defaults['online_required_refund_threshold']);
            }
        });

        // Backfill any row that predates the columns (Postgres applies the
        // column default to existing rows, but an explicit UPDATE keeps this
        // correct on drivers that do not, and is a no-op when it already is).
        DB::table('company_fraud_settings')
            ->whereNull('offline_refund_count_ceiling')
            ->update(['offline_refund_count_ceiling' => (int) $defaults['offline_refund_count_ceiling']]);
        DB::table('company_fraud_settings')
            ->whereNull('offline_refund_value_ceiling')
            ->update(['offline_refund_value_ceiling' => $defaults['offline_refund_value_ceiling']]);
        DB::table('company_fraud_settings')
            ->whereNull('online_required_refund_threshold')
            ->update(['online_required_refund_threshold' => $defaults['online_required_refund_threshold']]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_fraud_settings
                DROP CONSTRAINT IF EXISTS cfs_refund_exposure_non_negative');
            DB::statement('ALTER TABLE company_fraud_settings ADD CONSTRAINT cfs_refund_exposure_non_negative
                CHECK (offline_refund_count_ceiling >= 0
                   AND offline_refund_value_ceiling >= 0
                   AND online_required_refund_threshold >= 0)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('company_fraud_settings')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_fraud_settings
                DROP CONSTRAINT IF EXISTS cfs_refund_exposure_non_negative');
        }

        Schema::table('company_fraud_settings', function (Blueprint $table): void {
            foreach ([
                'offline_refund_count_ceiling',
                'offline_refund_value_ceiling',
                'online_required_refund_threshold',
            ] as $column) {
                if (Schema::hasColumn('company_fraud_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
