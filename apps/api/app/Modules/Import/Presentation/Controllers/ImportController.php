<?php

declare(strict_types=1);

namespace App\Modules\Import\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\FailedRowsExportService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Import\Services\SpreadsheetParserService;
use App\Modules\Import\Services\ValidationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    /**
     * Number of rows to show in preview
     */
    private const PREVIEW_ROW_LIMIT = 4;

    /**
     * Threshold for async processing - imports with fewer rows run synchronously
     */
    private const ASYNC_THRESHOLD = 100;

    public function __construct(
        private readonly ImportService $importService,
        private readonly ValidationEngine $validationEngine,
        private readonly CompanyContext $companyContext,
        private readonly SpreadsheetParserService $spreadsheetParser,
        private readonly FailedRowsExportService $failedRowsExportService,
    ) {}

    /**
     * List import jobs for the current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $jobs = ImportJob::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $jobs->map(fn (ImportJob $job) => $this->formatJob($job)),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    /**
     * Upload and create a new import job
     */
    public function store(Request $request): JsonResponse
    {
        // Extend time limit for large file parsing and validation (up to 5 minutes)
        set_time_limit(300);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'type' => ['required', 'string', new Enum(ImportType::class)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');
        $type = ImportType::from($request->input('type'));

        // Store the file
        $path = $file->store('imports/'.$tenantId, 'local');
        if ($path === false) {
            return response()->json(['error' => 'Failed to store file'], 500);
        }

        // Get the full file path
        $fullPath = Storage::disk('local')->path($path);

        // Create import job with temporary total_rows
        $job = $this->importService->createJob(
            tenantId: $tenantId,
            userId: $user->id,
            type: $type,
            filename: $file->getClientOriginalName(),
            filePath: $path,
            totalRows: 0
        );

        try {
            // Parse spreadsheet file (CSV, XLSX, XLS)
            $parseResult = $this->spreadsheetParser->parse($fullPath);

            // Add rows to the import job using batch insert (10x faster for large imports)
            $this->importService->addRowsBatch($job, $parseResult['rows']);

            // Validate headers
            $headerValidation = $this->validationEngine->validateHeaders(
                $parseResult['headers'],
                $type->getRequiredColumns(),
                $type->getOptionalColumns()
            );

            if (! $headerValidation['is_valid']) {
                $job->update([
                    'status' => ImportStatus::Failed,
                    'error_message' => 'Missing required columns: '.implode(', ', $headerValidation['missing']),
                ]);

                return response()->json([
                    'data' => $this->formatJob($job),
                    'errors' => [
                        'missing_columns' => $headerValidation['missing'],
                        'unknown_columns' => $headerValidation['unknown'],
                    ],
                ], 422);
            }

            // Update total rows
            $job->update(['total_rows' => count($parseResult['rows'])]);

            // Validate rows
            $this->importService->validateJob($job);

            /** @var ImportJob $freshJob */
            $freshJob = $job->fresh();

            return response()->json([
                'data' => $this->formatJob($freshJob),
            ], 201);
        } catch (\Exception $e) {
            $job->update([
                'status' => ImportStatus::Failed,
                'error_message' => 'Failed to parse file: '.$e->getMessage(),
            ]);

            return response()->json([
                'data' => $this->formatJob($job),
                'error' => 'Failed to parse file: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get import job details
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        return response()->json([
            'data' => $this->formatJob($job),
        ]);
    }

    /**
     * Get a preview of an import job (sample rows with validation status)
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        // Get sample rows for preview
        $sampleRows = $job->rows()
            ->orderBy('row_number')
            ->limit(self::PREVIEW_ROW_LIMIT)
            ->get();

        // Get headers from first row or column mapping
        $headers = $job->column_mapping
            ? array_keys($job->column_mapping)
            : ($sampleRows->first() ? array_keys($sampleRows->first()->data) : []);

        return response()->json([
            'data' => [
                'headers' => $headers,
                'rows' => $sampleRows->map(fn ($row) => [
                    'row_number' => $row->row_number,
                    'data' => $row->data,
                    'is_valid' => $row->is_valid,
                    'errors' => $row->errors ?? [],
                ]),
                'summary' => [
                    'total_rows' => $job->total_rows,
                    'valid_rows' => $job->successful_rows,
                    'invalid_rows' => $job->failed_rows,
                ],
            ],
        ]);
    }

    /**
     * Get all errors (validation and execution) for an import job
     */
    public function errors(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        // Get all rows with errors (validation or execution)
        $errorRows = $this->importService->getAllErrorRows($job);

        return response()->json([
            'data' => $errorRows->map(fn ($row) => [
                'row_number' => $row->row_number,
                'data' => $row->data,
                'errors' => $row->errors ?? [],
                'import_error' => $row->import_error,
                'error_type' => $row->import_error !== null ? 'execution' : 'validation',
            ]),
            'meta' => [
                'job_error_message' => $job->error_message,
                'validation_errors' => $errorRows->whereNull('import_error')->count(),
                'execution_errors' => $errorRows->whereNotNull('import_error')->count(),
            ],
        ]);
    }

    /**
     * Execute an import job.
     *
     * For small imports (< ASYNC_THRESHOLD rows), processes synchronously.
     * For large imports, dispatches to queue and returns 202 Accepted.
     * Real-time progress updates are broadcast via WebSocket for async jobs.
     *
     * Supports partial imports: valid rows are imported, invalid rows are skipped.
     * Returns import result with counts and a CSV download URL for failed rows.
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if (! $job->canStart()) {
            return response()->json([
                'error' => 'Import cannot be started. No valid rows to import.',
                'failed_rows' => $job->failed_rows,
                'valid_rows' => $job->getValidRowsCount(),
            ], 422);
        }

        // Use actual valid rows count for threshold check (more reliable than total_rows)
        // This prevents issues where total_rows might be 0 or stale
        $validRowsCount = $job->rows()->where('is_valid', true)->count();

        // Small imports: process synchronously for instant feedback
        // But only if we're confident the count is accurate (not 0 with large total_rows)
        if ($validRowsCount < self::ASYNC_THRESHOLD && $job->total_rows < self::ASYNC_THRESHOLD) {
            // Extend time limit for small synchronous imports (1 minute should be plenty)
            set_time_limit(60);

            $result = $this->importService->executeImport($job);

            /** @var ImportJob $freshJob */
            $freshJob = $job->fresh();

            // Generate failed rows CSV if there are any skipped/failed rows
            $failedRowsCsvUrl = null;
            if ($result['skipped_count'] > 0 || $result['execution_error_count'] > 0) {
                $failedRowsCsvUrl = $this->failedRowsExportService->getDownloadUrl($freshJob);
            }

            return response()->json([
                'data' => $this->formatJob($freshJob),
                'import_result' => [
                    'imported_count' => $result['imported_count'],
                    'skipped_count' => $result['skipped_count'],
                    'execution_error_count' => $result['execution_error_count'],
                    'total_rows' => $result['total_rows'],
                    'failed_rows_csv_url' => $failedRowsCsvUrl,
                ],
                'message' => $this->buildImportResultMessage($result),
            ]);
        }

        // Large imports: dispatch to queue for async processing
        ProcessImportJob::dispatch($job->id, $companyId);

        // Mark as queued (before actual processing begins)
        $job->update(['status' => ImportStatus::Pending]);

        /** @var ImportJob $freshJob */
        $freshJob = $job->fresh();

        return response()->json([
            'data' => $this->formatJob($freshJob),
            'message' => 'Import job queued for processing. Subscribe to WebSocket for real-time updates.',
        ], 202);
    }

    /**
     * Download failed rows CSV for an import job.
     */
    public function downloadFailedRows(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        // Generate or get existing CSV file
        $filePath = $this->failedRowsExportService->generateFailedRowsCsv($job);

        if ($filePath === null) {
            return response()->json(['error' => 'No failed rows to export'], 404);
        }

        $filename = sprintf('%s-failed-rows.csv', $job->original_filename);

        return response()->streamDownload(function () use ($filePath) {
            echo Storage::disk('local')->get($filePath);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Build a human-readable message for import results.
     *
     * @param  array{imported_count: int, skipped_count: int, execution_error_count: int, total_rows: int}  $result
     */
    private function buildImportResultMessage(array $result): string
    {
        $parts = [];
        $parts[] = sprintf('%d rows imported successfully', $result['imported_count']);

        if ($result['skipped_count'] > 0) {
            $parts[] = sprintf('%d rows skipped due to validation errors', $result['skipped_count']);
        }

        if ($result['execution_error_count'] > 0) {
            $parts[] = sprintf('%d rows failed during execution', $result['execution_error_count']);
        }

        return implode(', ', $parts).'.';
    }

    /**
     * Format job for JSON response
     *
     * @return array<string, mixed>
     */
    private function formatJob(ImportJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'original_filename' => $job->original_filename,
            'total_rows' => $job->total_rows,
            'processed_rows' => $job->processed_rows,
            'successful_rows' => $job->successful_rows,
            'failed_rows' => $job->failed_rows,
            'progress_percentage' => $job->getProgressPercentage(),
            'error_message' => $job->error_message,
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
