<?php

declare(strict_types=1);

namespace App\Modules\Import\Application\Jobs;

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
 */
final class ProcessProductImageImport implements ShouldQueue
{
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
        public readonly string $zipPath
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
        $job = ImportJob::find($this->importJobId);

        if ($job === null) {
            Log::error('ProcessProductImageImport: Import job not found', ['id' => $this->importJobId]);

            return;
        }

        try {
            // Update status to importing
            $job->update([
                'status' => ImportStatus::Importing,
                'started_at' => now(),
            ]);

            // Process ZIP file
            $results = $importService->processZipImport($job, $this->zipPath);

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
                'progress_percentage' => 100,
            ]);

            // Store error details if any failures
            if ($failureCount > 0) {
                $errors = collect($results)->where('success', false)->all();
                $rowsData = [];

                foreach ($errors as $index => $error) {
                    $rowsData[] = [
                        'row_number' => $index + 1,
                        'data' => [
                            'filename' => $error['filename'] ?? 'unknown',
                            'sku' => $error['sku'] ?? 'unknown',
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
            if (Storage::disk('local')->exists($this->zipPath)) {
                Storage::disk('local')->delete($this->zipPath);
            }
        }
    }
}
