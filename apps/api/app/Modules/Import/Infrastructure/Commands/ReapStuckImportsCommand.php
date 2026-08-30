<?php

declare(strict_types=1);

namespace App\Modules\Import\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Data\ImportErrorDetailData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportJobClaimService;
use App\Modules\Tenant\Domain\Tenant;

/**
 * Fail imports abandoned before or after a worker began execution.
 *
 * Scheduled every five minutes. This command must iterate tenant databases:
 * the central database in database-per-tenant deployments has no import_jobs
 * table, and a central sweep would silently leave every stale claim untouched.
 * Each candidate is finalized through the same terminal CAS as a live worker,
 * so a late worker and this reaper can never overwrite one another's status or
 * aggregate counters.
 */
final class ReapStuckImportsCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'imports:reap-stuck';

    /** @var string */
    protected $description = 'Fail import jobs whose claim or worker clock is stale';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ImportJobClaimService $claimService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $reaped = 0;
        $cutoff = now()->subMinutes(90);

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$reaped, $cutoff): int {
            ImportJob::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', ImportStatus::Importing->value)
                ->where(function ($query) use ($cutoff): void {
                    $query->where(function ($unstarted) use ($cutoff): void {
                        $unstarted->whereNull('worker_started_at')
                            ->where('claimed_at', '<', $cutoff);
                    })->orWhere(function ($started) use ($cutoff): void {
                        $started->whereNotNull('worker_started_at')
                            ->where('worker_started_at', '<', $cutoff);
                    });
                })
                ->lazyById(500)
                ->each(function (ImportJob $job) use (&$reaped): void {
                    $message = 'Import worker stopped reporting for more than 90 minutes. Re-upload the source file to retry.';
                    $won = $this->claimService->finalize(
                        $job,
                        ImportStatus::Failed,
                        ImportCountersData::fromJob($job),
                        ImportErrorCode::WorkerLost,
                        ImportErrorDetailData::from(['reason' => 'worker_timeout']),
                        $message,
                    );

                    if ($won) {
                        $reaped++;
                    }
                });

            return self::SUCCESS;
        });

        $this->info($reaped > 0 ? "Reaped {$reaped} stuck import(s)." : 'No stuck imports found.');

        return $exit;
    }
}
