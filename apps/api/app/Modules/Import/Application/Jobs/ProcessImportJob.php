<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Events\ImportCompleted;
use App\Modules\Import\Domain\Events\ImportProgressUpdated;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue job for processing import rows asynchronously.
 * Broadcasts progress updates every N rows.
 *
 * Tenant-isolation (api.scheduled-jobs.001): the constructor carries
 * tenantId so the queue worker can rebind tenant context via
 * {@see BindsTenantContext::withTenantContext()} BEFORE any DB access.
 * Every ImportJob / Company lookup inside handle() additionally
 * re-asserts `where('tenant_id', $this->tenantId)` as defense-in-depth
 * (mirrors the manual per-query scoping used by DispatchAppointmentReminder
 * under api.console-commands.002).
 */
final class ProcessImportJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of rows to process before broadcasting progress.
     */
    private const PROGRESS_BROADCAST_INTERVAL = 100;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run (1 hour).
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $importJobId,
        public readonly string $companyId,
        public readonly string $tenantId,
    ) {
        $this->onQueue('imports');
    }

    /**
     * Execute the job.
     */
    public function handle(ImportService $importService): void
    {
        $this->withTenantContext(function () use ($importService): void {
            $job = ImportJob::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->importJobId)
                ->first();

            if ($job === null) {
                Log::error('ProcessImportJob: Import job not found', ['id' => $this->importJobId]);

                return;
            }

            if (! $job->canStart()) {
                Log::warning('ProcessImportJob: Import job cannot be started', [
                    'id' => $this->importJobId,
                    'status' => $job->status->value,
                    'failed_rows' => $job->failed_rows,
                ]);

                return;
            }

            $company = Company::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->companyId)
                ->first();
            if ($company === null) {
                Log::error('ProcessImportJob: Company not found', ['id' => $this->companyId]);
                $this->failJob($job, 'Company not found');

                return;
            }

            $this->processImport($job, $importService, $company);
        });
    }

    /**
     * Process all valid rows in the import job.
     */
    private function processImport(ImportJob $job, ImportService $importService, Company $company): void
    {
        $job->update([
            'status' => ImportStatus::Importing,
            'started_at' => now(),
        ]);

        $validRows = $importService->getValidRows($job);
        $processedCount = 0;
        $successCount = 0;
        $failCount = 0;
        $totalRows = $validRows->count();

        // Broadcast initial progress
        $this->broadcastProgress($job, $company, $totalRows, $processedCount, $successCount, $failCount);

        foreach ($validRows as $row) {
            try {
                DB::transaction(function () use ($importService, $job, $row, &$successCount): void {
                    $entityId = $importService->importSingleRow($job, $row, $this->companyId);
                    $row->update([
                        'is_imported' => true,
                        'imported_entity_id' => $entityId,
                    ]);
                    $successCount++;
                });
            } catch (\Throwable $e) {
                $row->update([
                    'is_imported' => false,
                    'import_error' => $e->getMessage(),
                ]);
                $failCount++;
            }

            $processedCount++;
            $job->update(['processed_rows' => $processedCount]);

            // Broadcast progress at intervals
            if ($processedCount % self::PROGRESS_BROADCAST_INTERVAL === 0) {
                $this->broadcastProgress($job, $company, $totalRows, $processedCount, $successCount, $failCount);
            }
        }

        // Final status update
        $finalStatus = $failCount > 0 ? ImportStatus::Failed : ImportStatus::Completed;
        $job->update([
            'status' => $finalStatus,
            'successful_rows' => $successCount,
            'failed_rows' => $failCount,
            'completed_at' => now(),
        ]);

        // Broadcast completion
        $this->broadcastCompleted($job, $company, $totalRows, $successCount, $failCount, null);
    }

    /**
     * Broadcast progress update event.
     */
    private function broadcastProgress(
        ImportJob $job,
        Company $company,
        int $totalRows,
        int $processedRows,
        int $successfulRows,
        int $failedRows
    ): void {
        try {
            event(new ImportProgressUpdated(
                importJobId: $job->id,
                tenantId: $job->tenant_id,
                companyId: $company->id,
                status: $job->status,
                totalRows: $totalRows,
                processedRows: $processedRows,
                successfulRows: $successfulRows,
                failedRows: $failedRows,
                importType: $job->type->value,
                originalFilename: $job->original_filename,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast import progress', [
                'import_job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast completion event.
     */
    private function broadcastCompleted(
        ImportJob $job,
        Company $company,
        int $totalRows,
        int $successfulRows,
        int $failedRows,
        ?string $errorMessage
    ): void {
        try {
            event(new ImportCompleted(
                importJobId: $job->id,
                tenantId: $job->tenant_id,
                companyId: $company->id,
                status: $job->status,
                totalRows: $totalRows,
                successfulRows: $successfulRows,
                failedRows: $failedRows,
                importType: $job->type->value,
                originalFilename: $job->original_filename,
                errorMessage: $errorMessage,
                completedAt: CarbonImmutable::now(),
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast import completed', [
                'import_job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark the import job as failed.
     *
     * Called from inside withTenantContext closure in handle(); the
     * Company lookup re-asserts tenant_id for defense-in-depth.
     */
    private function failJob(ImportJob $job, string $errorMessage): void
    {
        $job->update([
            'status' => ImportStatus::Failed,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);

        $company = Company::query()
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->companyId)
            ->first();
        if ($company !== null) {
            $this->broadcastCompleted(
                $job,
                $company,
                $job->total_rows,
                0,
                $job->total_rows,
                $errorMessage
            );
        }
    }

    /**
     * Handle a job failure.
     *
     * Laravel queue invokes failed() in a fresh worker context, so we
     * must rebind tenant context here independently of handle().
     */
    public function failed(\Throwable $exception): void
    {
        $this->withTenantContext(function () use ($exception): void {
            $job = ImportJob::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->importJobId)
                ->first();

            if ($job !== null) {
                $job->update([
                    'status' => ImportStatus::Failed,
                    'error_message' => 'Job failed: '.$exception->getMessage(),
                    'completed_at' => now(),
                ]);

                $company = Company::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('id', $this->companyId)
                    ->first();
                if ($company !== null) {
                    $this->broadcastCompleted(
                        $job,
                        $company,
                        $job->total_rows,
                        $job->successful_rows,
                        $job->failed_rows,
                        'Job failed: '.$exception->getMessage()
                    );
                }
            }

            Log::error('ProcessImportJob failed', [
                'import_job_id' => $this->importJobId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        });
    }
}
