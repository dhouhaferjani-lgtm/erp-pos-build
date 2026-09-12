<?php

declare(strict_types=1);

namespace App\Modules\Import\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportRowExportService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Purge expired import source files while retaining their audit rows.
 *
 * Scheduled daily and executed once inside every tenant database. Retention is
 * measured from completed_at when present and created_at otherwise. Missing
 * files are successful no-ops; source_purged_at is the durable truth consumed
 * by the download endpoint and makes repeated sweeps idempotent.
 */
final class PurgeExpiredImportArtifactsCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'imports:purge-expired';

    /** @var string */
    protected $description = 'Delete import source files older than 90 days while keeping job rows';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ImportRowExportService $rowExportService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $purged = 0;
        $cutoff = now()->subDays(90);

        $exit = $this->forEachTenant(function (Tenant $tenant) use (&$purged, $cutoff): int {
            ImportJob::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('source_purged_at')
                ->where(function ($query) use ($cutoff): void {
                    $query->where('completed_at', '<', $cutoff)
                        ->orWhere(function ($createdFallback) use ($cutoff): void {
                            $createdFallback->whereNull('completed_at')
                                ->where('created_at', '<', $cutoff);
                        });
                })
                ->lazyById(500)
                ->each(function (ImportJob $job) use (&$purged): void {
                    // Correction exports are deleted by their own download, but an
                    // orphan must not outlive retention either — sweep it first, so
                    // a source that cannot be deleted does not strand it.
                    $this->rowExportService->deleteArtifacts($job);

                    $disk = Storage::disk('local');
                    $sourcePurged = ! $disk->exists($job->file_path);
                    if (! $sourcePurged) {
                        $sourcePurged = $disk->delete($job->file_path);
                    }

                    if (! $sourcePurged) {
                        Log::warning('import_jobs.expired_source_cleanup_failed', [
                            'id' => $job->id,
                            'tenant_id' => $job->tenant_id,
                            'storage_key' => $job->file_path,
                        ]);

                        return;
                    }

                    $updated = ImportJob::query()
                        ->where('tenant_id', $job->tenant_id)
                        ->where('id', $job->id)
                        ->whereNull('source_purged_at')
                        ->update(['source_purged_at' => now()]);

                    if ($updated === 1) {
                        $purged++;
                    }
                });

            return self::SUCCESS;
        });

        $this->info($purged > 0 ? "Purged {$purged} import source file(s)." : 'No expired import sources found.');

        return $exit;
    }
}
