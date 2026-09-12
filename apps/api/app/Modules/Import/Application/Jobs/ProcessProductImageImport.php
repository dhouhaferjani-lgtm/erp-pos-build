<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
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

    /** Company context added after ProductImages jobs first shipped. */
    public ?string $companyId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $importJobId,
        /** Disk-relative key on the local storage disk. */
        public readonly string $zipPath,
        public readonly string $tenantId,
        ?string $companyId = null,
    ) {
        $this->companyId = $companyId;
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

            // The persisted disk-relative key is authoritative for processing and
            // cleanup, including legacy payloads that serialized an absolute path.
            $storageKey = $job->file_path;

            if ($this->companyId === null) {
                $message = 'company_context_missing: Re-upload this ProductImages import so it can be processed in a company context.';
                Log::error('ProcessProductImageImport: Failed', [
                    'job_id' => $this->importJobId,
                    'error' => $message,
                ]);

                ImportJob::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('id', $this->importJobId)
                    ->whereNull('worker_started_at')
                    ->whereIn('status', [
                        ImportStatus::Pending->value,
                        ImportStatus::Validated->value,
                        ImportStatus::Importing->value,
                    ])
                    ->update([
                        'status' => ImportStatus::Failed->value,
                        // Every terminal failure carries a code, because operator
                        // surfaces render the code and never the raw message.
                        'error_code' => ImportErrorCode::InternalError->value,
                        'error_message' => $message,
                        'completed_at' => now(),
                    ]);

                $this->purgeSource($storageKey);

                return;
            }

            $observedStatus = $job->status;
            if ($observedStatus->canStartImport()) {
                $claim = $baseImportService->claimJob($job, $this->companyId);
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
                $results = $importService->processZipImport(
                    $job,
                    Storage::disk('local')->path($storageKey),
                    $this->companyId,
                );

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
                $this->purgeSource($storageKey);
            }
        });
    }

    private function purgeSource(string $storageKey): void
    {
        $disk = Storage::disk('local');
        $sourcePurged = ! $disk->exists($storageKey);
        if (! $sourcePurged) {
            $sourcePurged = $disk->delete($storageKey);
        }

        if ($sourcePurged) {
            ImportJob::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->importJobId)
                ->whereNull('source_purged_at')
                ->update(['source_purged_at' => now()]);

            return;
        }

        Log::warning('ProcessProductImageImport: Source cleanup failed', [
            'id' => $this->importJobId,
            'storage_key' => $storageKey,
        ]);
    }

    private function findImportJob(): ?ImportJob
    {
        return ImportJob::query()
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->importJobId)
            ->first();
    }
}
