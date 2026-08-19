<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Application\Services\InventoryVarianceAccountProvisioner;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Install the treasury-approved Option A inventory variance accounts into
 * existing tenant charts. Resolution is purpose-first and code-second: a
 * valid legacy purpose holder is authoritative, while an unpurposed 6586/7586
 * row may be promoted. Each definition has its own connection-bound
 * transaction/savepoint so one PostgreSQL error cannot leave the surrounding
 * tenants:migrate transaction in SQLSTATE 25P02.
 *
 * Invoke through tenants:run. Its child exit status is swallowed, so the last
 * console line and warning log are the stable deploy gate token.
 */
final class BackfillInventoryShrinkagePurposesCommand extends Command
{
    protected $signature = 'accounting:backfill-inventory-shrinkage-purposes
                            {--dry-run : Report changes without writing accounts}';

    protected $description = 'Backfill approved inventory shrinkage (6586) and gain (7586) purpose accounts.';

    public const SUMMARY_TOKEN_PREFIX = 'INVENTORY-SHRINKAGE-PURPOSE BACKFILL FAILURES:';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly InventoryVarianceAccountProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            $this->error('Tenant accounting tables are unavailable; run this command through tenants:run.');

            $token = self::SUMMARY_TOKEN_PREFIX.' 1';
            Log::warning($token);
            $this->line($token);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $promoted = 0;
        $satisfied = 0;
        $failures = 0;

        $companies = $this->database->table('companies')
            ->whereNull('deleted_at')
            ->select(['id', 'tenant_id', 'country_code'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            foreach ($this->provisioner->definitions((string) $company->country_code) as $definition) {
                try {
                    $outcome = $this->database->connection()->transaction(
                        fn (): string => $this->provisioner->applyDefinition(
                            (string) $company->id,
                            (string) $company->tenant_id,
                            $definition,
                            $dryRun,
                        ),
                    );
                    match ($outcome) {
                        'created' => $created++,
                        'promoted' => $promoted++,
                        default => $satisfied++,
                    };
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error($exception->getMessage());
                }
            }
        }

        $this->info(sprintf(
            '%sInventory variance purpose backfill: %d created; %d promoted; %d already satisfied; %d failed.',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $promoted,
            $satisfied,
            $failures,
        ));

        $token = self::SUMMARY_TOKEN_PREFIX.' '.$failures;
        Log::warning($token);
        $this->line($token);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
