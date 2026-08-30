<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Events\ImportCompleted;
use App\Modules\Import\Domain\Events\ImportProgressUpdated;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportJobClaimService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
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
    public function handle(
        ImportService $importService,
        UnitsProvisioningService $unitsProvisioning,
    ): void {
        $this->withTenantContext(function () use ($importService, $unitsProvisioning): void {
            $job = $this->findImportJob();

            if ($job === null) {
                Log::error('ProcessImportJob: Import job not found', ['id' => $this->importJobId]);

                return;
            }

            $observedStatus = $job->status;
            if ($observedStatus->canStartImport()) {
                $claim = $importService->claimJob($job);
                if (! $claim->won) {
                    Log::warning('ProcessImportJob: Import job already claimed by another worker', [
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
                Log::warning('ProcessImportJob: Import job cannot be started', [
                    'id' => $this->importJobId,
                    'status' => $observedStatus->value,
                ]);

                return;
            }

            if (! $importService->markWorkerStarted($job->id, $this->tenantId)) {
                Log::warning('ProcessImportJob: Duplicate delivery ignored', [
                    'id' => $this->importJobId,
                    'status' => $this->findImportJob()?->status->value ?? $job->status->value,
                ]);

                return;
            }
            $job = $this->findImportJob();
            if ($job === null) {
                return;
            }

            $company = Company::query()
                ->where('tenant_id', $this->tenantId)
                ->where('id', $this->companyId)
                ->first();
            if ($company === null) {
                Log::error('ProcessImportJob: Company not found', ['id' => $this->companyId]);
                $this->failJob($job, $importService, null, 'Company not found');

                return;
            }

            if (in_array('unit', $job->type->getOptionalColumns(), true)
                && ! $unitsProvisioning->hasVisibleUnits($company)) {
                $this->failJob(
                    $job,
                    $importService,
                    ImportErrorCode::UnitsNotSeeded,
                    'No units of measure are configured for this company; seed them in Settings → Units before importing',
                );

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
        // Rows that failed validation are skipped at execution but still count as
        // failed in the final tally (sync-path parity) — they never reach
        // is_imported=true, so the row-state tally below already includes them.
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

        $importService->finalizeImport($job, $this->companyId);

        // Final status update — mirrors ImportService::executeImport: status AND
        // counts come from row state after finalize, because a finalize phase may
        // demote rows it could not commit (GL opening balances post once, for the
        // whole file, after the loop). Nothing imported => Failed; partial success
        // completes.
        $counters = ImportCountersData::fromJob($job);
        $finalStatus = $counters->failedRows === $counters->totalRows
            ? ImportStatus::Failed
            : ImportStatus::Completed;
        if (! $importService->finalizeClaimedJob($job, $finalStatus, counters: $counters)) {
            return;
        }
        $job = $this->findImportJob();
        if ($job === null) {
            return;
        }

        // Broadcast completion
        $this->broadcastCompleted(
            $job,
            $company,
            $totalRows,
            $counters->successfulRows,
            $counters->failedRows,
            null,
        );
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
    private function failJob(
        ImportJob $job,
        ImportService $importService,
        ?ImportErrorCode $errorCode,
        string $errorMessage,
    ): void {
        $storedMessage = $errorCode === null
            ? $errorMessage
            : $errorCode->value.': '.$errorMessage;

        if (! $importService->finalizeClaimedJob(
            $job,
            ImportStatus::Failed,
            $errorCode,
            message: $storedMessage,
        )) {
            return;
        }
        $job = $this->findImportJob();
        if ($job === null) {
            return;
        }

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
            $job = $this->findImportJob();

            if ($job !== null) {
                $message = 'Job failed: '.$exception->getMessage();
                $won = ImportJobClaimService::finalizeFromFailedHandler(
                    $job,
                    ImportStatus::Failed,
                    ImportCountersData::fromJob($job),
                    null,
                    null,
                    $message,
                );

                $company = Company::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('id', $this->companyId)
                    ->first();
                if ($won && $company !== null) {
                    $job = $this->findImportJob();
                    if ($job === null) {
                        return;
                    }
                    $this->broadcastCompleted(
                        $job,
                        $company,
                        $job->total_rows,
                        $job->successful_rows,
                        $job->failed_rows,
                        $message
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

    private function findImportJob(): ?ImportJob
    {
        return ImportJob::query()
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->importJobId)
            ->first();
    }
}
