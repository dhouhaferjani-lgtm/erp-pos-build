<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
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
            $job = ImportJob::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->importJobId)
                ->first();

            if ($job === null) {
                Log::error('ProcessProductImageImport: Import job not found', ['id' => $this->importJobId]);

                return;
            }

            // The persisted key is authoritative for both new and pre-deployment
            // queue payloads. Legacy payloads carried an absolute zipPath and did
            // not contain companyId.
            $storageKey = $job->file_path;

            try {
                if ($this->companyId === null) {
                    throw new Exception(
                        'company_context_missing: Re-upload this ProductImages import so it can be processed in a company context.'
                    );
                }

                // Update status to importing
                $job->update([
                    'status' => ImportStatus::Importing,
                    'started_at' => now(),
                ]);

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

                // Update job with results
                $job->update([
                    'status' => ImportStatus::Completed,
                    'successful_rows' => $successCount,
                    'failed_rows' => $failureCount,
                    'processed_rows' => $totalProcessed,
                    'total_rows' => $totalProcessed,
                    'completed_at' => now(),
                ]);

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

                $job->update([
                    'status' => ImportStatus::Failed,
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
            } finally {
                // Cleanup ZIP file
                if (Storage::disk('local')->exists($storageKey)) {
                    Storage::disk('local')->delete($storageKey);
                }
            }
        });
    }
}
