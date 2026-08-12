<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Enable blind cash counting on settings rows persisted under the retired
 * automotive-only default. This runs unattended through tenants:migrate.
 *
 * The migration is deliberately settings-only and idempotent. It has no catch:
 * one guarded UPDATE is atomic, and a genuine database error must abort the
 * migration rather than be reported as a successful no-op.
 */
return new class extends Migration
{
    private const COMPLETION_TOKEN = 'SV-9 BLIND COUNT BACKFILL COMPLETE:';

    public function up(): void
    {
        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        if (! Schema::hasTable('company_fraud_settings')
            || ! Schema::hasColumn('company_fraud_settings', 'require_blind_cash_count')) {
            Log::warning(sprintf(
                '%s tenant=%s changed=0 skipped=0 schema=missing',
                self::COMPLETION_TOKEN,
                $tenantKey,
            ));

            return;
        }

        $total = DB::table('company_fraud_settings')->count();
        $changed = DB::table('company_fraud_settings')
            ->where('require_blind_cash_count', false)
            ->update(['require_blind_cash_count' => true]);

        Schema::table('company_fraud_settings', function (Blueprint $table): void {
            $table->boolean('require_blind_cash_count')->default(true)->change();
        });

        Log::warning(sprintf(
            '%s tenant=%s changed=%d skipped=%d',
            self::COMPLETION_TOKEN,
            $tenantKey,
            $changed,
            $total - $changed,
        ));
    }

    public function down(): void
    {
        // Forward-only policy migration: prior false values are not recoverable.
    }
};
