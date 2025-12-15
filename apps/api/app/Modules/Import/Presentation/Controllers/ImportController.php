<?php

declare(strict_types=1);

namespace App\Modules\Import\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Import\Services\SpreadsheetParserService;
use App\Modules\Import\Services\ValidationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Enum;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $importService,
        private readonly ValidationEngine $validationEngine,
        private readonly CompanyContext $companyContext,
        private readonly SpreadsheetParserService $spreadsheetParser,
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

            // Add rows to the import job
            foreach ($parseResult['rows'] as $rowNumber => $data) {
                $this->importService->addRow($job, $rowNumber, $data);
            }

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
     * Get validation errors for an import job
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

        $failedRows = $this->importService->getFailedRows($job);

        return response()->json([
            'data' => $failedRows->map(fn ($row) => [
                'row_number' => $row->row_number,
                'data' => $row->data,
                'errors' => $row->errors,
            ]),
        ]);
    }

    /**
     * Execute an import job
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
                'error' => 'Import cannot be started. Fix validation errors first.',
                'failed_rows' => $job->failed_rows,
            ], 422);
        }

        $this->importService->executeImport($job);

        /** @var ImportJob $freshJob */
        $freshJob = $job->fresh();

        return response()->json([
            'data' => $this->formatJob($freshJob),
        ]);
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
