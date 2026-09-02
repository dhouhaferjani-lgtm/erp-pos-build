<?php

declare(strict_types=1);

namespace App\Modules\Import\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Application\Jobs\ProcessProductImageImport;
use App\Modules\Import\Application\Services\ModuleEntitlementCheck;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Enums\BarcodeGroupClassification;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\FailedRowsExportService;
use App\Modules\Import\Services\ImportJobClaimService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Import\Services\ImportUnitErrorSummaryService;
use App\Modules\Import\Services\ResultWorkbookService;
use App\Modules\Import\Services\SpreadsheetParserService;
use App\Modules\Import\Services\ValidationEngine;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        private readonly ImportJobClaimService $importJobClaimService,
        private readonly ImportUnitErrorSummaryService $unitErrorSummary,
        private readonly ValidationEngine $validationEngine,
        private readonly CompanyContext $companyContext,
        private readonly SpreadsheetParserService $spreadsheetParser,
        private readonly FailedRowsExportService $failedRowsExportService,
        private readonly ResultWorkbookService $resultWorkbookService,
        private readonly UnitCatalogQueryInterface $unitCatalog,
        private readonly ModuleEntitlementCheck $moduleEntitlement,
    ) {}

    /**
     * List import jobs for the current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', new Enum(ImportStatus::class)],
            'type' => ['sometimes', new Enum(ImportType::class)],
            'q' => ['sometimes', 'string', 'max:255'],
        ]);
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        $jobs = ImportJob::where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['q']), fn ($query) => $query->where('original_filename', 'like', '%'.$filters['q'].'%'))
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $jobs->map(fn (ImportJob $job) => array_merge(
                $this->formatJob($job, false),
                ['unattributed' => $job->company_id === null],
            )),
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

        // Different validation for ProductImages (ZIP) vs other types (CSV/XLSX)
        $typeInput = $request->input('type');
        $isProductImages = $typeInput === 'product_images';

        $request->validate([
            'file' => $isProductImages
                ? ['required', 'file', 'mimes:zip', 'max:102400'] // 100MB for ZIP
                : ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'type' => ['required', 'string', new Enum(ImportType::class)],
            'options' => ['sometimes', 'array'],
            'options.location_code' => ['sometimes', 'string', 'max:100'],
            'options.enrichment_enabled' => ['sometimes', 'boolean'],
            'options.price_authority' => ['sometimes', 'in:ttc,ht,margin'],
            'options.placement_mode' => ['sometimes', 'in:strict,auto_create'],
            'options.placement_node_types' => ['sometimes', 'array'],
            'options.placement_node_types.*' => ['required', new Enum(LocationNodeType::class)],
            'options.duplicate_policy' => ['sometimes', 'in:override,skip'],
            'options.multi_location_confirmed' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $type = ImportType::from($request->input('type'));
        $this->moduleEntitlement->ensure($type, $user);
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_SOURCE_HASH_FAILED',
                    'message' => 'The uploaded source file could not be read for integrity verification.',
                ],
            ], 500);
        }

        $sourceHash = hash_file('sha256', $realPath);
        if (! is_string($sourceHash)) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_SOURCE_HASH_FAILED',
                    'message' => 'The uploaded source file could not be read for integrity verification.',
                ],
            ], 500);
        }

        // Deprecated types stay in the enum so historical `import_jobs` rows
        // remain readable, but they can never start a NEW import (ruling D4).
        // Refused as a validation error on `type` — never a 500, and never the
        // silent success the old movement-less writer used to give.
        $deprecation = $type->deprecationMessage();
        if ($deprecation !== null) {
            throw ValidationException::withMessages(['type' => [$deprecation]]);
        }

        if (in_array('unit', $type->getOptionalColumns(), true)
            && $this->unitCatalog->visibleActiveUnitCount($company->id) === 0) {
            return response()->json([
                'error' => [
                    'code' => ImportErrorCode::UnitsNotSeeded->value,
                    'message' => 'No units of measure are configured for this company; seed them in Settings → Units before importing',
                    'details' => [
                        'company_id' => $company->id,
                    ],
                ],
            ], 422);
        }

        $columnMapping = $request->has('column_mapping')
            ? json_decode($request->input('column_mapping'), true)
            : null;
        $options = $this->optionsFromRequest($request);

        // Special handling for ProductImages (ZIP file)
        if ($type === ImportType::ProductImages) {
            return $this->handleProductImagesUpload($file, $user, $tenantId, $companyId, $sourceHash);
        }

        // A GL opening-balance import consumes the company's one-shot opening slot:
        // it posts a historical journal entry and LOCKS an OpeningBalanceBatch that
        // nobody can delete afterwards. Every equivalent action on the opening-batch
        // API is gated behind accounts.manage (Accounting routes.php), so the import
        // surface's own imports.manage grant must not be a way around it.
        if ($type === ImportType::OpeningBalances && ! $user->can('accounts.manage')) {
            return $this->openingBalancesForbidden();
        }

        // Store the file
        $path = $file->store('imports/'.$tenantId, 'local');
        if ($path === false) {
            return response()->json(['error' => 'Failed to store file'], 500);
        }

        // Get the full file path
        $fullPath = Storage::disk('local')->path($path);

        // Create import job with temporary total_rows
        /** @var array<string, string>|null $columnMapping */
        $job = $this->importService->createJob(
            tenantId: $tenantId,
            companyId: $companyId,
            userId: $user->id,
            type: $type,
            filename: $file->getClientOriginalName(),
            filePath: $path,
            totalRows: 0,
            sourceHash: $sourceHash,
            columnMapping: $columnMapping,
            options: $options
        );

        try {
            // Parse spreadsheet file (CSV, XLSX, XLS)
            $parseResult = $this->spreadsheetParser->parse($fullPath);

            $mappedRows = $this->importService->applyColumnMapping($parseResult['rows'], $columnMapping);
            $mappedHeaders = $columnMapping !== null
                ? array_values($columnMapping)
                : $parseResult['headers'];

            // Add rows to the import job using batch insert (10x faster for large imports)
            $this->importService->addRowsBatch($job, $mappedRows);

            // Validate headers
            $headerValidation = $this->validationEngine->validateHeaders(
                $mappedHeaders,
                $type->getRequiredColumns(),
                $type->getOptionalColumns()
            );

            if (! $headerValidation['is_valid']) {
                $job->update([
                    'status' => ImportStatus::Failed,
                    'error_message' => 'Missing required columns: '.implode(', ', $headerValidation['missing']),
                ]);

                return response()->json([
                    'data' => $this->formatJob($job, true),
                    'errors' => [
                        'missing_columns' => $headerValidation['missing'],
                        'unknown_columns' => $headerValidation['unknown'],
                    ],
                ], 422);
            }

            // Update total rows
            $job->update(['total_rows' => count($mappedRows)]);

            // Validate rows
            $this->importService->validateJob($job);
            $this->importService->prepareProductPlacements($job, $companyId);

            /** @var ImportJob $freshJob */
            $freshJob = $job->fresh();

            return response()->json([
                'data' => $this->formatJob($freshJob, true),
            ], 201);
        } catch (\Exception $e) {
            $job->update([
                'status' => ImportStatus::Failed,
                'error_message' => 'Failed to parse file: '.$e->getMessage(),
            ]);

            return response()->json([
                'data' => $this->formatJob($job, true),
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        return response()->json([
            'data' => $this->formatJob($job, true),
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        // Get sample rows for preview
        $sampleRows = $job->rows()
            ->orderBy('row_number')
            ->limit(self::PREVIEW_ROW_LIMIT)
            ->get();

        // Get headers from first row or column mapping
        $headers = $job->column_mapping
            ? array_keys($job->column_mapping)
            : ($sampleRows->first()
                ? array_values(array_filter(
                    array_keys($sampleRows->first()->data),
                    static fn (string $header): bool => ! str_starts_with($header, '_'),
                ))
                : []);
        $duplicateCensus = $job->options['duplicate_census'] ?? null;
        $refusalDetailsByRow = [];
        if (is_array($duplicateCensus) && is_array($duplicateCensus['refused'] ?? null)) {
            foreach ($duplicateCensus['refused'] as $detail) {
                if (is_array($detail) && is_int($detail['row_number'] ?? null)) {
                    $refusalDetailsByRow[$detail['row_number']] = $detail;
                }
            }
        }

        $validRowCount = $job->rows()->where('is_valid', true)->count();
        $invalidRowCount = $job->rows()->where('is_valid', false)->count();
        $barcodeConflictValidRows = $this->validBarcodeConflictRowCount($job, $duplicateCensus);
        $preview = [
            'headers' => $headers,
            'rows' => $sampleRows->map(fn ($row) => [
                'row_number' => $row->row_number,
                'data' => array_filter(
                    $row->data,
                    static fn (string $header): bool => ! str_starts_with($header, '_'),
                    ARRAY_FILTER_USE_KEY,
                ),
                'is_valid' => $row->is_valid,
                'errors' => $row->errors ?? [],
                'duplicate_bucket' => $row->duplicate_bucket?->value,
                'duplicate_advisory' => $refusalDetailsByRow[$row->row_number] ?? null,
            ]),
            'summary' => [
                'total_rows' => $job->total_rows,
                'valid_rows' => $validRowCount - $barcodeConflictValidRows,
                'invalid_rows' => $invalidRowCount + $barcodeConflictValidRows,
                'multi_location_products' => $this->barcodeCensusCount($duplicateCensus, 'multi_location_products'),
                'barcode_identity_conflict_rows' => $this->barcodeCensusCount($duplicateCensus, 'barcode_identity_conflict_rows'),
            ],
            'placement' => $this->importService->productPlacementPreview($job),
            'error_summary' => $this->unitErrorSummary->summarize($job),
        ];
        if (is_array($duplicateCensus)) {
            $preview['duplicates'] = $duplicateCensus;
        }

        return response()->json(['data' => $preview]);
    }

    /**
     * Get all errors (validation and execution) for an import job
     *
     * Supports pagination for large error sets via per_page query parameter.
     * Returns both validation errors (from validation phase) and execution errors (from import phase).
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        // Get pagination parameters
        $perPage = (int) $request->query('per_page', 50);
        $perPage = min(max($perPage, 1), 500); // Clamp between 1-500

        // Get paginated error rows
        $errorRows = $job->rows()
            ->where(function ($query) {
                $query->where('is_valid', false)
                    ->orWhereNotNull('import_error');
            })
            ->orderBy('row_number')
            ->paginate($perPage);

        // Calculate summary counts (use raw counts for efficiency on large datasets)
        $validationErrorCount = $job->rows()->where('is_valid', false)->count();
        $executionErrorCount = $job->rows()->whereNotNull('import_error')->count();

        return response()->json([
            'data' => $errorRows->map(fn ($row) => [
                'row_number' => $row->row_number,
                'data' => array_filter(
                    $row->data,
                    static fn (string $header): bool => ! str_starts_with($header, '_'),
                    ARRAY_FILTER_USE_KEY,
                ),
                'errors' => $row->errors ?? [],
                'warnings' => $row->warnings,
                'import_error' => $row->import_error,
                'error_type' => $row->import_error !== null ? 'execution' : 'validation',
            ]),
            'meta' => [
                'current_page' => $errorRows->currentPage(),
                'last_page' => $errorRows->lastPage(),
                'per_page' => $errorRows->perPage(),
                'total' => $errorRows->total(),
                'job_error_message' => $job->error_message,
                'error_summary' => $this->unitErrorSummary->summarize($job),
                'validation_errors' => $validationErrorCount,
                'execution_errors' => $executionErrorCount,
            ],
        ]);
    }

    public function updateOptions(Request $request, string $id): JsonResponse
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        if (! in_array($job->status, [ImportStatus::Pending, ImportStatus::Validating, ImportStatus::Validated], true)) {
            return response()->json(['error' => ['code' => 'IMPORT_ALREADY_STARTED']], 409);
        }

        $request->validate([
            'options' => ['required', 'array'],
            'options.location_code' => ['sometimes', 'string', 'max:100'],
            'options.enrichment_enabled' => ['sometimes', 'boolean'],
            'options.price_authority' => ['sometimes', 'in:ttc,ht,margin'],
            'options.placement_mode' => ['sometimes', 'in:strict,auto_create'],
            'options.placement_node_types' => ['sometimes', 'array'],
            'options.placement_node_types.*' => ['required', new Enum(LocationNodeType::class)],
            'options.duplicate_policy' => ['sometimes', 'in:override,skip'],
            'options.multi_location_confirmed' => ['sometimes', 'boolean'],
        ]);

        $requestOptions = $this->optionsFromRequest($request) ?? [];

        $job->update([
            'options' => array_merge($job->options ?? [], $requestOptions),
        ]);

        $this->importService->validateJob($job);
        $this->importService->prepareProductPlacements($job, $companyId);

        /** @var ImportJob $freshJob */
        $freshJob = $job->fresh();

        return response()->json([
            'data' => $this->formatJob($freshJob, true),
        ]);
    }

    /**
     * Get error summary for an import job (lightweight, no row data).
     *
     * Useful for dashboards and progress indicators that need quick stats.
     */
    public function errorSummary(Request $request, string $id): JsonResponse
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        // Get counts efficiently without loading row data
        $validationErrorCount = $job->rows()->where('is_valid', false)->count();
        $executionErrorCount = $job->rows()->whereNotNull('import_error')->count();
        $totalErrorCount = $job->rows()
            ->where(function ($query) {
                $query->where('is_valid', false)
                    ->orWhereNotNull('import_error');
            })
            ->count();

        return response()->json([
            'data' => [
                'total_errors' => $totalErrorCount,
                'validation_errors' => $validationErrorCount,
                'execution_errors' => $executionErrorCount,
                'has_errors' => $totalErrorCount > 0,
                'job_error_message' => $job->error_message,
                'error_summary' => $this->unitErrorSummary->summarize($job),
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        // A job of a retired type can only be a leftover created before the
        // deprecation. Refuse it here rather than letting it reach
        // ImportService::importRow(), whose writer no longer exists.
        $deprecation = $job->type->deprecationMessage();
        if ($deprecation !== null) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_TYPE_RETIRED',
                    'message' => $deprecation,
                ],
            ], 422);
        }

        // Posting is the act that consumes the one-shot opening slot — gate it here
        // too, not only at upload, so a job created before the grant was revoked
        // cannot still be executed. See store().
        if ($job->type === ImportType::OpeningBalances && $request->user()?->can('accounts.manage') !== true) {
            return $this->openingBalancesForbidden();
        }

        if ($job->status === ImportStatus::Failed
            && $job->started_at === null
            && $job->completed_at === null) {
            return response()->json([
                'error' => 'Import cannot be started. No valid rows to import.',
                'failed_rows' => $job->failed_rows,
                'valid_rows' => $job->getValidRowsCount(),
            ], 422);
        }

        // Status precondition, separate from the "no valid rows" refusal below.
        // A Completed/Failed/Importing/Validating job must be refused for what it
        // is, not with a misleading "no valid rows" message — executing this
        // endpoint twice re-applies the whole file, and this is the tenant-#1
        // onboarding path. Only Validated/Pending are start-eligible
        // (ImportStatus::canStartImport).
        if ($job->status === ImportStatus::Importing) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_ALREADY_STARTED',
                    'message' => 'This import has already started.',
                ],
            ], 409);
        }

        if (! $job->status->canStartImport()) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_NOT_EXECUTABLE',
                    'message' => 'This import has already been executed or is currently running, and cannot be executed again.',
                    'details' => [
                        'status' => $job->status->value,
                    ],
                ],
            ], 422);
        }

        $duplicateCensus = $job->options['duplicate_census'] ?? null;
        if ($this->barcodeCensusCount($duplicateCensus, 'multi_location_products') > 0
            && ($job->options['multi_location_confirmed'] ?? null) !== true) {
            return response()->json([
                'error' => [
                    'code' => 'MULTI_LOCATION_CONFIRMATION_REQUIRED',
                    'message' => 'Confirm that repeated product barcodes should open stock in every listed location.',
                    'details' => [
                        'multi_location_products' => $this->barcodeCensusCount(
                            $duplicateCensus,
                            'multi_location_products',
                        ),
                    ],
                ],
            ], 422);
        }

        $claim = $this->importJobClaimService->claim($job, $companyId);
        if (! $claim->won) {
            return response()->json([
                'error' => [
                    'code' => 'IMPORT_ALREADY_STARTED',
                    'message' => 'This import has already started.',
                ],
            ], 409);
        }

        $job->refresh();

        // The winner alone may inspect row state. If validation left no row to
        // apply, restore the exact status claimed so an operator can fix rows
        // and execute again; release is non-terminal and worker-safe by CAS.
        $validRowsCount = $this->importService->getValidRows($job)->count();
        if ($validRowsCount === 0) {
            $this->importJobClaimService->release($job, $claim->priorStatus);

            return response()->json([
                'error' => 'Import cannot be started. No valid rows to import.',
                'failed_rows' => $job->failed_rows,
                'valid_rows' => 0,
            ], 422);
        }

        // Small imports: process synchronously for instant feedback
        // But only if we're confident the count is accurate (not 0 with large total_rows)
        if ($validRowsCount < self::ASYNC_THRESHOLD && $job->total_rows < self::ASYNC_THRESHOLD) {
            // Extend time limit for small synchronous imports (1 minute should be plenty)
            set_time_limit(60);

            $result = $this->importService->executeImport($job, $claim->priorStatus);

            /** @var ImportJob $freshJob */
            $freshJob = $job->fresh();

            // Generate failed rows CSV if there are any skipped/failed rows
            $failedRowsCsvUrl = null;
            if ($result['skipped_count'] > 0 || $result['execution_error_count'] > 0) {
                $failedRowsCsvUrl = $this->failedRowsExportService->getDownloadUrl($freshJob);
            }

            return response()->json([
                'data' => $this->formatJob($freshJob, true),
                'import_result' => [
                    'imported_count' => $result['imported_count'],
                    'skipped_count' => $result['skipped_count'],
                    'execution_error_count' => $result['execution_error_count'],
                    'preview_drift_count' => $result['preview_drift_count'],
                    'total_rows' => $result['total_rows'],
                    'failed_rows_csv_url' => $failedRowsCsvUrl,
                ],
                'message' => $this->buildImportResultMessage($result),
            ]);
        }

        // Large imports: dispatch to queue for async processing.
        // tenantId is passed so the worker can rebind tenant context via
        // BindsTenantContext::withTenantContext() before any DB access
        // (api.scheduled-jobs.001).
        ProcessImportJob::dispatch($job->id, $companyId, $tenantId);

        /** @var ImportJob $freshJob */
        $freshJob = $job->fresh();

        return response()->json([
            'data' => $this->formatJob($freshJob, true),
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

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
     * Download the original source uploaded for an import job.
     */
    public function downloadSourceFile(Request $request, string $id): StreamedResponse|JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($job === null) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        if ($job->source_purged_at !== null) {
            return response()->json([
                'error' => [
                    'code' => 'source_purged',
                    'message' => 'The original import source has expired and was purged.',
                ],
            ], 410);
        }

        if (! Storage::disk('local')->exists($job->file_path)) {
            return response()->json(['error' => 'Import source file not found'], 404);
        }

        return response()->streamDownload(function () use ($job): void {
            echo Storage::disk('local')->get($job->file_path);
        }, $job->original_filename);
    }

    /**
     * Discard an import job and its retained artifacts.
     */
    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        if (! Str::isUuid($id)) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $job = ImportJob::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($job === null) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        if ($job->status === ImportStatus::Importing || $job->successful_rows !== 0) {
            return $this->importHasEffectsConflict();
        }

        $deleted = ImportJob::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('status', '!=', ImportStatus::Importing->value)
            ->where('successful_rows', 0)
            ->delete();

        if ($deleted !== 1) {
            return $this->importHasEffectsConflict();
        }

        if (! Storage::disk('local')->delete($job->file_path)) {
            Log::warning('import_jobs.discard_orphaned_source', [
                'id' => $job->id,
                'tenant_id' => $tenantId,
                'file_path' => $job->file_path,
            ]);
        }

        return response()->noContent();
    }

    private function importHasEffectsConflict(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'IMPORT_HAS_EFFECTS',
                'message' => 'This import cannot be discarded because it is running or has posted effects. Use the opening-balance reset or stock adjustment flows to correct posted data.',
            ],
        ], 409);
    }

    /**
     * Download the XLSX result workbook for an import job.
     */
    public function downloadResultWorkbook(Request $request, string $id): BinaryFileResponse|JsonResponse
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

        if ($mismatch = $this->companyMismatch($job, $companyId)) {
            return $mismatch;
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($job->type, $user);

        $path = $this->resultWorkbookService->generate($job);

        return response()->download(
            $path,
            "import-{$job->id}-result.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend();
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
    private function formatJob(ImportJob $job, bool $withWarningSummary): array
    {
        $counters = ImportCountersData::fromJob($job);

        return [
            'id' => $job->id,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'original_filename' => $job->original_filename,
            'total_rows' => $counters->totalRows,
            'processed_rows' => $job->processed_rows,
            'successful_rows' => $counters->successfulRows,
            'skipped_rows' => $counters->skippedRows,
            'failed_rows' => $counters->failedRows,
            'warning_rows' => $this->countWarningRows($job),
            'warning_summary' => $withWarningSummary && $job->status->isTerminal()
                ? $this->warningSummary($job)
                : null,
            'error_summary' => $withWarningSummary
                ? $this->unitErrorSummary->summarize($job)
                : null,
            'options' => $job->options,
            'multi_location_products' => $this->barcodeCensusCount(
                $job->options['duplicate_census'] ?? null,
                'multi_location_products',
            ),
            'barcode_identity_conflict_rows' => $this->barcodeCensusCount(
                $job->options['duplicate_census'] ?? null,
                'barcode_identity_conflict_rows',
            ),
            'progress_percentage' => $job->getProgressPercentage(),
            'error_message' => $job->error_message,
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    /**
     * Count rows with at least one warning without hydrating row models.
     */
    private function countWarningRows(ImportJob $job): int
    {
        if ($job->getConnection()->getDriverName() === 'sqlite') {
            return $job->rows()
                ->whereNotNull('warnings')
                ->where('warnings', '!=', '[]')
                ->count();
        }

        return $job->rows()->whereRaw('jsonb_array_length(warnings) > 0')->count();
    }

    /**
     * @return array<string, int>
     */
    private function warningSummary(ImportJob $job): array
    {
        $summary = [];

        foreach ($job->rows()->whereNotNull('warnings')->get(['warnings']) as $row) {
            $warnings = $row->getAttribute('warnings');
            if (! is_array($warnings) || $warnings === []) {
                continue;
            }

            $rowCodes = [];
            foreach ($warnings as $warning) {
                if (! is_array($warning)) {
                    continue;
                }

                $code = $warning['code'] ?? null;
                if (! is_string($code) || $code === '') {
                    continue;
                }

                $rowCodes[$code] = true;
            }

            foreach (array_keys($rowCodes) as $code) {
                $summary[$code] = ($summary[$code] ?? 0) + 1;
            }
        }

        ksort($summary);

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionsFromRequest(Request $request): ?array
    {
        if (! $request->has('options')) {
            return null;
        }

        $options = [];

        $locationCode = $request->input('options.location_code');
        if (is_string($locationCode)) {
            $options['location_code'] = $locationCode;
        }

        if ($request->has('options.enrichment_enabled')) {
            $options['enrichment_enabled'] = $request->boolean('options.enrichment_enabled');
        }

        $priceAuthority = $request->input('options.price_authority');
        if (is_string($priceAuthority)) {
            $options['price_authority'] = $priceAuthority;
        }

        $placementMode = $request->input('options.placement_mode');
        if (is_string($placementMode)) {
            $options['placement_mode'] = $placementMode;
        }

        $placementNodeTypes = $request->input('options.placement_node_types');
        if (is_array($placementNodeTypes)) {
            $options['placement_node_types'] = array_values($placementNodeTypes);
        }

        $duplicatePolicy = $request->input('options.duplicate_policy');
        if (is_string($duplicatePolicy)) {
            $options['duplicate_policy'] = $duplicatePolicy;
        }

        if ($request->has('options.multi_location_confirmed')) {
            $options['multi_location_confirmed'] = $request->boolean('options.multi_location_confirmed');
        }

        return $options;
    }

    private function barcodeCensusCount(mixed $duplicateCensus, string $key): int
    {
        if (! is_array($duplicateCensus)
            || ! is_array($duplicateCensus['barcode_groups'] ?? null)
            || ! is_array($duplicateCensus['barcode_groups']['counts'] ?? null)) {
            return 0;
        }

        $value = $duplicateCensus['barcode_groups']['counts'][$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    private function validBarcodeConflictRowCount(ImportJob $job, mixed $duplicateCensus): int
    {
        if (! is_array($duplicateCensus)
            || ! is_array($duplicateCensus['barcode_groups'] ?? null)
            || ! is_array($duplicateCensus['barcode_groups']['groups'] ?? null)) {
            return 0;
        }

        $rowNumbers = [];
        foreach ($duplicateCensus['barcode_groups']['groups'] as $group) {
            if (! is_array($group)
                || ($group['classification'] ?? null) !== BarcodeGroupClassification::BarcodeIdentityConflict->value
                || ! is_array($group['row_numbers'] ?? null)) {
                continue;
            }

            foreach ($group['row_numbers'] as $rowNumber) {
                if (is_int($rowNumber)) {
                    $rowNumbers[$rowNumber] = true;
                }
            }
        }

        $validConflictRows = 0;
        foreach (array_chunk(array_keys($rowNumbers), 500) as $chunk) {
            $validConflictRows += $job->rows()
                ->where('is_valid', true)
                ->whereIn('row_number', $chunk)
                ->count();
        }

        return $validConflictRows;
    }

    /**
     * GL opening balances are an accounting act, not merely a data load.
     */
    private function openingBalancesForbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'OPENING_BALANCES_REQUIRE_ACCOUNTS_MANAGE',
                'message' => 'Importing GL opening balances posts a locked, permanent opening journal entry and requires the accounts.manage permission.',
                'details' => [
                    'required_permission' => 'accounts.manage',
                ],
            ],
        ], 403);
    }

    /**
     * Handle ProductImages ZIP upload (special case).
     */
    private function handleProductImagesUpload(
        UploadedFile $file,
        User $user,
        string $tenantId,
        string $companyId,
        string $sourceHash,
    ): JsonResponse {
        // Store the ZIP file
        $path = $file->store('imports/'.$tenantId.'/product-images', 'local');
        if ($path === false) {
            return response()->json(['error' => 'Failed to store ZIP file'], 500);
        }

        // Create import job
        $job = $this->importService->createJob(
            tenantId: $tenantId,
            companyId: $companyId,
            userId: $user->id,
            type: ImportType::ProductImages,
            filename: $file->getClientOriginalName(),
            filePath: $path,
            totalRows: 0, // Will be set after ZIP extraction
            sourceHash: $sourceHash,
        );

        // Mark as pending BEFORE dispatching — same ordering rule as execute():
        // ProcessProductImageImport advances the job to Importing as soon as a
        // worker picks it up, and a status write after the dispatch can clobber
        // that back to Pending.
        $job->update(['status' => ImportStatus::Pending]);

        // Dispatch queue job for async ZIP processing.
        // Tenant and company are serialized because queue workers run without
        // CompanyContext; tenantId also drives BindsTenantContext rebinding.
        ProcessProductImageImport::dispatch($job->id, $path, $tenantId, $companyId);

        /** @var ImportJob $freshJob */
        $freshJob = $job->fresh();

        return response()->json([
            'data' => $this->formatJob($freshJob, true),
            'message' => 'Product images import queued for processing. The ZIP will be extracted and images will be uploaded asynchronously.',
        ], 202);
    }

    private function companyMismatch(ImportJob $job, string $companyId): ?JsonResponse
    {
        if ($job->company_id === null || $job->company_id === $companyId) {
            return null;
        }

        return response()->json([
            'error' => [
                'code' => 'IMPORT_COMPANY_MISMATCH',
                'message' => 'This import job belongs to a different company. Switch back to the company that created it.',
            ],
        ], 409);
    }
}
