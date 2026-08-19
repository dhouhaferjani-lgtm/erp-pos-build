<?php

declare(strict_types=1);

use App\Console\Commands\BackfillInventoryShrinkagePurposesCommand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Install Option A inventory-variance purposes unattended on existing tenant
 * charts before destructive-loss posting starts resolving the new shrinkage
 * counter-account. The frozen legacy seeders remain byte-identical; this data
 * migration delegates to the same purpose-first/code-second command used for
 * manual repair.
 *
 * The command runs inside a connection-bound transaction. During PostgreSQL
 * migrations that transaction is a savepoint, so a failed account definition
 * is rolled back without poisoning the migration runner's outer transaction
 * with SQLSTATE 25P02. A failed chart is reported through a warning-level,
 * tenant-attributed deploy token and does not stop later tenants from migrating.
 */
return new class extends Migration
{
    private const GATE_TOKEN = 'INVENTORY-SHRINKAGE-PURPOSE BACKFILL MIGRATION:';

    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            return;
        }

        $tenantKey = (string) (tenant()?->getTenantKey() ?? 'unknown');

        try {
            $exitCode = 1;
            $output = '';

            DB::connection($this->getConnection())->transaction(function () use (&$exitCode, &$output): void {
                $exitCode = Artisan::call(BackfillInventoryShrinkagePurposesCommand::class);
                $output = trim(Artisan::output());
            });

            Log::info(sprintf(
                "Migration backfill_inventory_shrinkage_purposes [tenant %s]: command exited %d.\n%s",
                $tenantKey,
                $exitCode,
                $output,
            ));
            Log::warning(sprintf(
                '%s tenant=%s status=%s exit=%d.%s',
                self::GATE_TOKEN,
                $tenantKey,
                $exitCode === 0 ? 'ok' : 'FAILED',
                $exitCode,
                $exitCode === 0
                    ? ''
                    : ' Re-run `php artisan tenants:run accounting:backfill-inventory-shrinkage-purposes`'
                        .' for per-company reasons, then map the approved purpose or repair the missing parent.',
            ));
        } catch (Throwable $exception) {
            Log::error(sprintf(
                '%s tenant=%s status=FAILED exit=exception. %s',
                self::GATE_TOKEN,
                $tenantKey,
                $exception->getMessage(),
            ));
        }
    }

    public function down(): void
    {
        // Irreversible data repair: the accounts may already carry posted lines.
    }
};
