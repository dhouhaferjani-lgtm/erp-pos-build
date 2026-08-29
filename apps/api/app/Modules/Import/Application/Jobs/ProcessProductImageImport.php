<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Application\Services\ProductImageImportService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Queue job for processing product image ZIP imports asynchronously.
 *
 * Tenant-isolation (api.scheduled-jobs.002): the constructor carries
 * tenantId so the queue worker can rebind tenant context via
 * {@see BindsTenantContext::withTenantContext()} BEFORE any DB access.
 * The ImportJob lookup inside handle() additionally re-asserts
 * `where('tenant_id', $this->tenantId)` as defense-in-depth (mirrors
 * the manual per-query scoping pattern used by ProcessImportJob /
 * DispatchAppointmentReminder).
 */
final class ProcessProductImageImport implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run (30 minutes for large ZIPs).
     */
    public int $timeout = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $importJobId,
        public readonly string $zipPath,
        public readonly string $tenantId,
    ) {
        $this->onQueue('imports');
    }

    /**
     * Execute the job.
     */
    public function handle(
        ProductImageImportService $importService,
        ImportService $baseImportService
    ): void {
        $this->withTenantContext(function () use ($importService, $baseImportService): void {
            $job = $this->findImportJob();

            if ($job === null) {
                Log::error('ProcessProductImageImport: Import job not found', ['id' => $this->importJobId]);

                return;
            }

            $observedStatus = $job->status;
            if ($observedStatus->canStartImport()) {
                $claim = $baseImportService->claimJob($job);
                if (! $claim->won) {
                    Log::warning('ProcessProductImageImport: Import job already claimed by another worker', [
                        'id' => $this->importJobId,
                        'status' => $claim->priorStatus->value,
                    ]);

                    return;
                }
                $job = $this->findImportJob();
                if ($job === null) {
                    return;
                }
            } elseif ($observedStatus !== ImportStatus::Importing) {
                Log::warning('ProcessProductImageImport: Import job cannot be started', [
                    'id' => $this->importJobId,
                    'status' => $observedStatus->value,
                ]);

                return;
            }

            if (! $baseImportService->markWorkerStarted($job->id, $this->tenantId)) {
                Log::warning('ProcessProductImageImport: Duplicate delivery ignored', [
                    'id' => $this->importJobId,
                    'status' => $this->findImportJob()?->status->value ?? $job->status->value,
                ]);

                return;
            }
            $job = $this->findImportJob();
            if ($job === null) {
                return;
            }

            try {
                // Process ZIP file
                $results = $importService->processZipImport($job, $this->zipPath);

                // Calculate counts
                $successCount = collect($results)->where('success', true)->count();
                $failureCount = collect($results)->where('success', false)->count();
                $totalProcessed = count($results);

                // Store error details if any failures
                if ($failureCount > 0) {
                    $errors = collect($results)->where('success', false)->all();
                    $rowsData = [];

                    foreach ($errors as $index => $error) {
                        $rowsData[] = [
                            'row_number' => $index + 1,
                            'data' => [
                                'filename' => $error['filename'],
                                'sku' => $error['sku'],
                            ],
                            'is_valid' => false,
                            'errors' => ['file' => [$error['error'] ?? 'Unknown error']],
                        ];
                    }

                    if (count($rowsData) > 0) {
                        $baseImportService->addRowsBatch($job, $rowsData);
                    }
                }

                $baseImportService->finalizeClaimedJob(
                    $job,
                    ImportStatus::Completed,
                    counters: new ImportCountersData(
                        totalRows: $totalProcessed,
                        successfulRows: $successCount,
                        skippedRows: 0,
                        failedRows: $failureCount,
                    ),
                );
                $job = $this->findImportJob();
                if ($job === null) {
                    return;
                }

                Log::info('ProcessProductImageImport: Completed successfully', [
                    'job_id' => $this->importJobId,
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                ]);
            } catch (Exception $e) {
                Log::error('ProcessProductImageImport: Failed', [
                    'job_id' => $this->importJobId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $baseImportService->finalizeClaimedJob(
                    $job,
                    ImportStatus::Failed,
                    message: $e->getMessage(),
                );
            } finally {
                // Cleanup ZIP file
                if (Storage::disk('local')->exists($this->zipPath)) {
                    Storage::disk('local')->delete($this->zipPath);
                }

                ImportJob::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('id', $this->importJobId)
                    ->whereNull('source_purged_at')
                    ->update(['source_purged_at' => now()]);
            }
        });
    }

    private function findImportJob(): ?ImportJob
    {
        return ImportJob::query()
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->importJobId)
            ->first();
    }
}
