<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Import\Domain\Data\ClaimResult;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Data\ImportErrorDetailData;
use App\Modules\Import\Domain\Data\ImportRowResultsData;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\DuplicatePolicy;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\Exceptions\CodedImportRowException;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Shared\Contracts\CompositeItemServiceInterface;
use App\Shared\Contracts\PartnerResolverInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\ProductResolverInterface;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use App\Shared\DTOs\CategoryResolutionDTO;
use App\Shared\DTOs\ProductTaxDefaultDTO;
use App\Shared\Enums\CategoryResolutionOutcome;
use App\Shared\Enums\ProductIdentityFailure;
use App\Shared\Enums\ProductIdentityMatch;
use App\Shared\Enums\ProductTaxDefaultSource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ImportService
{
    public function __construct(
        private readonly ValidationEngine $validationEngine,
        private readonly CompanyContext $companyContext,
        private readonly PartnerServiceInterface $partnerService,
        private readonly PartnerResolverInterface $partnerResolver,
        private readonly ProductServiceInterface $productService,
        private readonly ProductResolverInterface $productResolver,
        private readonly CompositeItemServiceInterface $compositeItemService,
        private readonly NumericFieldNormalizer $numericNormalizer,
        private readonly PartiesRowMapper $partiesRowMapper,
        private readonly PartiesBalancesPhase $partiesBalancesPhase,
        private readonly AccountingBalancesPhase $accountingBalancesPhase,
        private readonly ProductPriceResolver $productPriceResolver,
        private readonly TaxDefaultResolverInterface $taxDefaultResolver,
        private readonly ProductOpeningStockPhase $productOpeningStockPhase,
        private readonly ProductPlacementImportService $productPlacementImportService,
        private readonly UnitResolver $unitResolver,
        private readonly DuplicateCensusService $duplicateCensus,
        private readonly ImportJobClaimService $importJobClaimService,
    ) {}

    /**
     * Create a new import job
     *
     * @param  array<string, string>|null  $columnMapping
     * @param  array<string, string|bool>|null  $options
     */
    public function createJob(
        string $tenantId,
        string $companyId,
        string $userId,
        ImportType $type,
        string $filename,
        string $filePath,
        int $totalRows,
        ?string $sourceHash = null,
        ?array $columnMapping = null,
        ?array $options = null
    ): ImportJob {
        return ImportJob::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'type' => $type,
            'status' => ImportStatus::Pending,
            'original_filename' => $filename,
            'file_path' => $filePath,
            'source_hash' => $sourceHash,
            'total_rows' => $totalRows,
            'column_mapping' => $columnMapping,
            'options' => $options,
        ]);
    }

    /**
     * Query-side seam owned by G-6a for G-3b's controller index method.
     *
     * @return LengthAwarePaginator<int, ImportJob>
     */
    public function paginateJobs(string $tenantId, int $page, int $perPage): LengthAwarePaginator
    {
        return ImportJob::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(
                perPage: max(1, $perPage),
                page: max(1, $page),
            );
    }

    /**
     * Add a row to an import job
     *
     * @param  array<string, mixed>  $data
     */
    public function addRow(ImportJob $job, int $rowNumber, array $data): ImportRow
    {
        return ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => $rowNumber,
            'data' => $this->numericNormalizer->normalize($data, $job->type->getValidationRules()),
            'is_valid' => false,
        ]);
    }

    /**
     * Append a non-blocking warning to a row. Warnings never affect validity,
     * import success, failed-row counts, or the failed-rows export.
     */
    public function addRowWarning(ImportRow $row, ImportWarningCode $code, string $detail): void
    {
        $warnings = $row->warnings ?? [];
        $warnings[] = ['code' => $code->value, 'detail' => $detail];
        $row->update(['warnings' => $warnings]);
    }

    /**
     * Add multiple rows to an import job in batches (optimized for large imports).
     *
     * This method uses bulk insert instead of individual Eloquent creates,
     * reducing 10,000 DB round trips to ~10 batched inserts.
     *
     * @param  array<int, array<string, mixed>>  $rows  Row data keyed by row number
     * @param  int  $batchSize  Number of rows per batch insert
     */
    public function addRowsBatch(ImportJob $job, array $rows, int $batchSize = 1000): void
    {
        $now = now();
        $rules = $job->type->getValidationRules();
        $batches = array_chunk($rows, max($batchSize, 1), true);

        foreach ($batches as $batch) {
            $insertData = [];
            foreach ($batch as $rowNumber => $data) {
                $insertData[] = [
                    'id' => (string) Str::uuid(),
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'data' => json_encode($this->numericNormalizer->normalize($data, $rules)),
                    'is_valid' => false,
                    'is_imported' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('import_rows')->insert($insertData);
        }
    }

    /**
     * Validate all rows in an import job.
     *
     * For large imports, uses chunked processing with batch updates
     * to avoid memory issues and reduce DB round trips.
     */
    public function validateJob(ImportJob $job): void
    {
        $job->update(['status' => ImportStatus::Validating]);

        $rules = $job->type->getValidationRules();
        $messages = $job->type->getValidationMessages();
        $validCount = 0;
        $invalidCount = 0;

        // Process in chunks to avoid loading all rows into memory
        $job->rows()
            ->orderBy('row_number')
            ->chunk(500, function ($rows) use ($rules, $messages, $job, &$validCount, &$invalidCount): void {
                $updates = [];

                foreach ($rows as $row) {
                    $result = $this->validationEngine->validate(
                        $row->data,
                        $rules,
                        $job->tenant_id,
                        $messages,
                    );

                    $updates[] = [
                        'id' => $row->id,
                        'is_valid' => $result['is_valid'],
                        'errors' => $result['errors'] ?: null,
                        'outcome' => $result['is_valid']
                            ? ImportRowOutcome::Pending
                            : ImportRowOutcome::Failed,
                        'import_error_code' => $result['is_valid']
                            ? null
                            : ImportErrorCode::ValidationFailed,
                    ];

                    if ($result['is_valid']) {
                        $validCount++;
                    } else {
                        $invalidCount++;
                    }
                }

                // Batch update using CASE statements for efficiency
                $this->batchUpdateValidation($updates);
            });

        $job->update([
            'status' => $validCount === 0 ? ImportStatus::Failed : ImportStatus::Validated,
            'processed_rows' => $invalidCount,
            'successful_rows' => 0,
            'failed_rows' => $invalidCount,
        ]);

        if ($job->type === ImportType::Parties) {
            $this->applyPartiesExtraValidation($job);
        }

        if ($job->type === ImportType::Products && $job->total_rows > 0) {
            $this->duplicateCensus->census($job->refresh(), $this->companyContext->requireCompanyId());
        }
    }

    public function prepareProductPlacements(ImportJob $job, string $companyId): void
    {
        $this->productPlacementImportService->prepareJob($job, $companyId);
    }

    /**
     * @return array{max_depth: int, nodes_to_create: list<array<string, mixed>>, placements_to_set: list<array<string, mixed>>}
     */
    public function productPlacementPreview(ImportJob $job): array
    {
        return $this->productPlacementImportService->preview($job);
    }

    private function applyPartiesExtraValidation(ImportJob $job): void
    {
        $openingBalancesLocked = $this->partiesBalancesPhase->openingBalancesLocked(
            $this->companyContext->requireCompanyId()
        );

        $job->rows()
            ->where('is_valid', true)
            ->orderBy('row_number')
            ->chunk(500, function ($rows) use ($openingBalancesLocked): void {
                foreach ($rows as $row) {
                    $extraErrors = $this->partiesRowMapper->extraValidationErrors($row->data);
                    if ($openingBalancesLocked && $this->hasAnyBalanceColumn($row->data)) {
                        $extraErrors[] = 'Opening balances are locked for this company.';
                    }

                    if ($extraErrors === []) {
                        continue;
                    }

                    $errors = $row->errors ?? [];
                    $errors['opening_balance'] = array_values(array_merge(
                        $errors['opening_balance'] ?? [],
                        $extraErrors
                    ));

                    $row->update([
                        'is_valid' => false,
                        'errors' => $errors,
                        'outcome' => ImportRowOutcome::Failed,
                        'import_error_code' => ImportErrorCode::ValidationFailed,
                    ]);
                }
            });

        $job->update([
            'status' => $job->rows()
                ->where('is_valid', true)
                ->where('outcome', ImportRowOutcome::Pending)
                ->exists()
                ? ImportStatus::Validated
                : ImportStatus::Failed,
            'processed_rows' => $job->rows()->where('outcome', ImportRowOutcome::Failed)->count(),
            'successful_rows' => 0,
            'failed_rows' => $job->rows()->where('is_valid', false)->count(),
        ]);
    }

    /**
     * Batch update validation results using raw SQL for efficiency.
     *
     * @param  array<array{id: string, is_valid: bool, errors: array<string, array<string>>|null, outcome: ImportRowOutcome, import_error_code: ImportErrorCode|null}>  $updates
     */
    private function batchUpdateValidation(array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        foreach ($updates as $update) {
            DB::table('import_rows')
                ->where('id', $update['id'])
                ->update([
                    'is_valid' => $update['is_valid'],
                    'errors' => $update['errors'] ? json_encode($update['errors']) : null,
                    'outcome' => $update['outcome']->value,
                    'import_error_code' => $update['import_error_code']?->value,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Get failed rows for an import job
     *
     * @return Collection<int, ImportRow>
     */
    public function getFailedRows(ImportJob $job): Collection
    {
        return $job->rows()
            ->where('is_valid', false)
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Get valid rows still awaiting application for an import job.
     *
     * `is_imported = false` is part of the filter, not an afterthought: it makes
     * every re-entry into the execution loop resume-shaped instead of
     * replay-shaped. A row that already landed is never applied a second time,
     * whatever the job's status column happens to say.
     *
     * @return Collection<int, ImportRow>
     */
    public function getValidRows(ImportJob $job): Collection
    {
        return $job->rows()
            ->where('is_valid', true)
            ->where('is_imported', false)
            ->where('outcome', ImportRowOutcome::Pending)
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Get rows with execution errors for an import job
     *
     * @return Collection<int, ImportRow>
     */
    public function getExecutionErrorRows(ImportJob $job): Collection
    {
        return $job->rows()
            ->whereNotNull('import_error')
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Get all rows with any type of error (validation or execution)
     *
     * @return Collection<int, ImportRow>
     */
    public function getAllErrorRows(ImportJob $job): Collection
    {
        return $job->rows()
            ->where(function ($query) {
                $query->where('is_valid', false)
                    ->orWhereNotNull('import_error');
            })
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Execute import for valid rows.
     *
     * Supports partial imports: only valid rows are imported, invalid rows are skipped.
     * Returns import result data including counts and any skipped rows.
     *
     * @return array{imported_count: int, skipped_count: int, execution_error_count: int, preview_drift_count: int, total_rows: int}
     */
    public function executeImport(ImportJob $job, ?ImportStatus $priorStatus = null): array
    {
        // Direct service consumers in the existing backend tests enter through
        // this public API without an HTTP controller. Delegate their ownership
        // transition to the same CAS service; the HTTP path already arrives
        // claimed and therefore does not repeat it.
        if ($job->status->canStartImport()) {
            $claim = $this->importJobClaimService->claim($job);
            if (! $claim->won) {
                throw new RuntimeException('Import has already been started.');
            }
            $priorStatus = $claim->priorStatus;
            $job->refresh();
        }

        if ($job->status !== ImportStatus::Importing) {
            throw new RuntimeException('Import cannot be started. No valid rows to import.');
        }

        if ($this->getValidRows($job)->isEmpty()) {
            if ($priorStatus !== null) {
                $this->importJobClaimService->release($job, $priorStatus);
            }

            throw new RuntimeException('Import cannot be started. No valid rows to import.');
        }

        if (! $this->importJobClaimService->markWorkerStarted($job->id, $job->tenant_id)) {
            throw new RuntimeException('Import worker has already started.');
        }
        $job->refresh();

        $validRows = $this->getValidRows($job);
        $initialCounts = $this->outcomeCounts($job);
        $processedCount = $initialCounts['processed'];
        $executionFailCount = 0;
        $previewDriftCount = 0;

        foreach ($validRows as $row) {
            if ($this->processPendingRow($job, $row) === ImportRowOutcome::Failed) {
                $executionFailCount++;
            }
            $warnings = $row->refresh()->warnings ?? [];
            if (array_any(
                $warnings,
                static fn (array $warning): bool => $warning['code'] === ImportWarningCode::PreviewDrift->value,
            )) {
                $previewDriftCount++;
            }

            $processedCount++;
            $job->update(['processed_rows' => $processedCount]);
        }

        $this->finalizeImport($job, $this->companyContext->requireCompanyId());

        // Status AND counts come from row state after finalize, because a finalize
        // phase may demote rows that staged fine yet could not be committed. GL
        // opening balances post once, for the whole file, AFTER the loop — so the
        // loop's optimistic tally would otherwise report a green "N imported" for a
        // file that changed nothing. An import that imported nothing has Failed.
        $counters = ImportCountersData::fromJob($job);
        $status = ($counters->successfulRows + $counters->skippedRows) === 0
            ? ImportStatus::Failed
            : ImportStatus::Completed;
        $this->importJobClaimService->finalize($job, $status, $counters, null, null);

        return [
            'imported_count' => $counters->successfulRows,
            'skipped_count' => $counters->skippedRows,
            'execution_error_count' => $executionFailCount,
            'preview_drift_count' => $previewDriftCount,
            'total_rows' => $counters->totalRows,
        ];
    }

    public function markWorkerStarted(string $jobId, string $tenantId): bool
    {
        return $this->importJobClaimService->markWorkerStarted($jobId, $tenantId);
    }

    public function claimJob(ImportJob $job, ?string $companyId = null): ClaimResult
    {
        return $this->importJobClaimService->claim($job, $companyId);
    }

    public function finalizeClaimedJob(
        ImportJob $job,
        ImportStatus $terminal,
        ?ImportErrorCode $code = null,
        ?ImportErrorDetailData $detail = null,
        ?string $message = null,
        ?ImportCountersData $counters = null,
    ): bool {
        return $this->importJobClaimService->finalize(
            $job,
            $terminal,
            $counters ?? ImportCountersData::fromJob($job),
            $code,
            $detail,
            $message,
        );
    }

    /**
     * Apply one pending row with its durable terminal decision.
     *
     * Successful decisions commit beside the entity mutation. Failures are
     * deliberately recorded only after that transaction has rolled back.
     */
    public function processPendingRow(
        ImportJob $job,
        ImportRow $row,
        ?string $companyId = null,
    ): ImportRowOutcome {
        try {
            return DB::transaction(function () use ($job, $row, $companyId): ImportRowOutcome {
                $companyId ??= $this->companyContext->requireCompanyId();
                $decision = $this->duplicateCensus->decide($job, $row, $companyId);
                $currentBucket = $decision->bucket;
                $warnings = $row->warnings ?? [];
                $decisionWarnings = [];
                if ($decision->locationUnresolved
                    && ! array_any(
                        $warnings,
                        static fn (array $warning): bool => ($warning['code'] ?? null) === ImportWarningCode::LocationUnresolved->value,
                    )) {
                    $decisionWarnings[] = [
                        'code' => ImportWarningCode::LocationUnresolved->value,
                        'detail' => 'Location code could not be resolved.',
                    ];
                }
                if ($row->duplicate_bucket !== null && $row->duplicate_bucket !== $currentBucket) {
                    $decisionWarnings[] = [
                        'code' => ImportWarningCode::PreviewDrift->value,
                        'detail' => sprintf(
                            'Preview classified this row as %s; execution resolved it as %s.',
                            $row->duplicate_bucket->value,
                            $currentBucket->value,
                        ),
                    ];
                }

                $winnerRow = $decision->winnerRowNumber;
                if ($winnerRow !== null) {
                    $decisionWarnings[] = [
                        'code' => ImportWarningCode::DuplicateInFile->value,
                        'detail' => sprintf('A later row for this product and location wins (row %d).', $winnerRow),
                    ];
                    $row->update([
                        'is_imported' => false,
                        'outcome' => ImportRowOutcome::DuplicateLoser,
                        'warnings' => array_merge($warnings, $decisionWarnings),
                        'import_error' => null,
                        'import_error_code' => null,
                        'import_error_detail' => null,
                    ]);

                    return ImportRowOutcome::DuplicateLoser;
                }

                $policy = DuplicatePolicy::tryFrom((string) ($job->options['duplicate_policy'] ?? ''))
                    ?? DuplicatePolicy::Override;
                if ($policy === DuplicatePolicy::Skip && $this->isExistingBucket($currentBucket)) {
                    $row->update([
                        'is_imported' => false,
                        'outcome' => ImportRowOutcome::DuplicateSkipped,
                        'warnings' => array_merge($warnings, $decisionWarnings) ?: null,
                        'import_error' => null,
                        'import_error_code' => null,
                        'import_error_detail' => null,
                    ]);

                    return ImportRowOutcome::DuplicateSkipped;
                }

                $entityId = $this->importRow($job, $row, $companyId);
                $warnings = array_merge($row->refresh()->warnings ?? [], $decisionWarnings);
                $row->update([
                    'is_imported' => true,
                    'imported_entity_id' => $entityId,
                    'outcome' => ImportRowOutcome::Imported,
                    'warnings' => $warnings === [] ? null : $warnings,
                    'import_error' => null,
                    'import_error_code' => null,
                    'import_error_detail' => null,
                ]);

                return ImportRowOutcome::Imported;
            });
        } catch (\Throwable $exception) {
            $coded = $this->codedFailure($exception);
            DB::table('import_rows')->where('id', $row->id)->update([
                'is_imported' => false,
                'import_error' => $exception->getMessage(),
                'import_error_code' => $coded['code']->value,
                'import_error_detail' => $coded['detail'] === []
                    ? null
                    : json_encode($coded['detail'], JSON_THROW_ON_ERROR),
                'outcome' => ImportRowOutcome::Failed->value,
                'updated_at' => now(),
            ]);

            return ImportRowOutcome::Failed;
        }
    }

    private function isExistingBucket(DuplicateBucket $bucket): bool
    {
        return match ($bucket) {
            DuplicateBucket::ExistingSku,
            DuplicateBucket::ExistingBarcode,
            DuplicateBucket::ExistingName => true,
            DuplicateBucket::New,
            DuplicateBucket::InFile,
            DuplicateBucket::Refused => false,
        };
    }

    /** @return array{processed: int, successful: int, skipped: int, failed: int} */
    public function outcomeCounts(ImportJob $job): array
    {
        $counts = $job->rows()
            ->reorder()
            ->selectRaw('outcome, COUNT(*) AS aggregate')
            ->groupBy('outcome')
            ->toBase()
            ->pluck('aggregate', 'outcome');

        $imported = (int) ($counts[ImportRowOutcome::Imported->value] ?? 0);
        $skipped = (int) ($counts[ImportRowOutcome::DuplicateSkipped->value] ?? 0)
            + (int) ($counts[ImportRowOutcome::DuplicateLoser->value] ?? 0);
        $failed = (int) ($counts[ImportRowOutcome::Failed->value] ?? 0)
            + (int) ($counts[ImportRowOutcome::OpeningLocked->value] ?? 0);

        return [
            'processed' => $imported + $skipped + $failed,
            'successful' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{code: ImportErrorCode, detail: array{
     *   supplied?: string,
     *   accepted?: list<string>,
     *   candidates?: list<array{id: string, code: string, name: string, category: string, tier: string}>,
     *   candidate_skus?: list<string>,
     *   sku?: string,
     *   existing_product_id?: string
     * }}
     */
    private function codedFailure(\Throwable $exception): array
    {
        if ($exception instanceof CodedImportRowException) {
            return ['code' => $exception->errorCode, 'detail' => $exception->detail];
        }

        $message = $exception->getMessage();
        if (str_starts_with($message, ImportErrorCode::SkuHeldByDeletedProduct->value.':')) {
            preg_match('/SKU\s+([^\s]+)\s+is held/', $message, $matches);

            return [
                'code' => ImportErrorCode::SkuHeldByDeletedProduct,
                'detail' => isset($matches[1]) ? ['sku' => $matches[1]] : [],
            ];
        }
        if (str_starts_with($message, ImportErrorCode::VatHeldByDeletedPartner->value.':')) {
            return ['code' => ImportErrorCode::VatHeldByDeletedPartner, 'detail' => []];
        }

        return ['code' => ImportErrorCode::InternalError, 'detail' => []];
    }

    /**
     * Import a single row (public API for queue job).
     *
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     * @return string The ID of the created entity
     */
    public function importSingleRow(ImportJob $job, ImportRow $row, ?string $companyId = null): string
    {
        return $this->importRow($job, $row, $companyId);
    }

    /**
     * Import a single row
     *
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importRow(ImportJob $job, ImportRow $row, ?string $companyId = null): string
    {
        return match ($job->type) {
            ImportType::Parties => $this->importParty($job, $row, $companyId),
            ImportType::Partners => $this->importPartner($job->tenant_id, $row->data, $companyId),
            ImportType::Products => $this->importProduct($job, $row, $companyId),
            // Retired by owner ruling D4: the writer this used to call
            // (InventoryService::upsertStockLevel) set an absolute quantity
            // with no movement and no document. Opening stock now rides the
            // Products import. The case survives only for historical reads —
            // ImportController::execute() refuses these jobs before they get
            // here, so this arm is the last line of defence.
            ImportType::StockLevels => throw new RuntimeException(
                'The stock levels import is no longer supported. Import opening stock with the Products import (quantity, purchase_price, location_code).'
            ),
            ImportType::OpeningBalances => $this->stageOpeningBalance($row),
            ImportType::ProductImages => throw new RuntimeException('Product image import is not yet supported'),
            ImportType::CompositeItems => $this->importCompositeItem($job->tenant_id, $row->data, $companyId),
        };
    }

    /**
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importParty(ImportJob $job, ImportRow $row, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();
        $data = $row->data;
        $identity = $this->partnerResolver->resolve(
            $job->tenant_id,
            $companyId,
            is_string($data['code'] ?? null) ? $data['code'] : null,
            is_string($data['tax_id'] ?? null) ? $data['tax_id'] : null,
            (string) ($data['name'] ?? ''),
        );
        if ($identity->partnerId !== null) {
            $data['code'] = $identity->code;
        } elseif ($this->hasAnyBalanceColumn($data) && $this->emptyString($data['code'] ?? null)) {
            $data['code'] = $this->syntheticPartnerCode($companyId, $data);
            $row->update(['data' => $data]);
            $this->addRowWarning($row, ImportWarningCode::CodeGenerated, 'A stable import code was generated for this partner.');
        }

        return $this->importPartner($job->tenant_id, $this->partiesRowMapper->toPartnerData($data), $companyId);
    }

    /** @param array<string, mixed> $data */
    private function syntheticPartnerCode(string $companyId, array $data): string
    {
        $vat = preg_replace('/\s+/', '', mb_strtoupper(trim((string) ($data['tax_id'] ?? '')))) ?? '';
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($data['name'] ?? '')) ?? ''));
        $identity = $vat !== '' ? $vat : $name;

        return 'IMP-'.substr(hash('sha256', $companyId.$identity), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasAnyBalanceColumn(array $data): bool
    {
        return ! $this->emptyString($data['opening_balance'] ?? null)
            || ! $this->emptyString($data['opening_balance_customer'] ?? null)
            || ! $this->emptyString($data['opening_balance_supplier'] ?? null);
    }

    private function emptyString(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    public function finalizeImport(ImportJob $job, string $companyId): void
    {
        $results = match ($job->type) {
            ImportType::Parties => $this->partiesBalancesPhase->run($job->refresh(), $companyId),
            ImportType::Products => $this->productOpeningStockPhase->run($job->refresh(), $companyId),
            ImportType::OpeningBalances => $this->accountingBalancesPhase->run($job->refresh(), $companyId),
            default => [],
        };

        foreach ($results as $result) {
            $row = ImportRow::find($result['row_id']);
            if ($row === null) {
                continue;
            }

            $warnings = $row->warnings ?? [];
            if ($result['code'] !== '') {
                $warningCode = ImportWarningCode::from($result['code']);
                $warnings[] = ['code' => $warningCode->value, 'detail' => $result['detail']];
            }

            $data = $row->data;
            $data['_results'] = ImportRowResultsData::fromStorage($data['_results'] ?? [])
                ->merged($this->finalizationResultPhase($job->type), $result['results'])
                ->toStorage();
            $openingLocked = in_array('error: opening_locked', $result['results'], true);
            $update = ['data' => $data, 'warnings' => $warnings === [] ? null : $warnings];
            if ($openingLocked) {
                $update = array_merge($update, [
                    'is_imported' => false,
                    'outcome' => ImportRowOutcome::OpeningLocked,
                    'import_error' => 'Opening balances are locked; post an adjustment instead.',
                    'import_error_code' => ImportErrorCode::InternalError,
                    'import_error_detail' => ['supplied' => 'opening_locked'],
                ]);
            }
            $row->update($update);
        }
    }

    private function finalizationResultPhase(ImportType $type): string
    {
        return match ($type) {
            ImportType::Parties => ImportRowResultsData::PHASE_PARTIES_BALANCES,
            ImportType::Products => ImportRowResultsData::PHASE_OPENING_STOCK,
            ImportType::OpeningBalances => ImportRowResultsData::PHASE_ACCOUNTING_BALANCES,
            default => ImportRowResultsData::PHASE_LEGACY,
        };
    }

    /**
     * Import a partner row
     *
     * Handles smart type merging via PartnerServiceInterface.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importPartner(string $tenantId, array $data, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();

        return $this->partnerService->upsertWithTypeMerge($tenantId, $companyId, $data);
    }

    /**
     * Import a product row via ProductServiceInterface.
     *
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importProduct(ImportJob $job, ImportRow $row, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();
        $data = $row->data;
        $sourceMaskWasCaptured = $this->sourceMaskWasCaptured($row);
        $unitWasInSource = array_key_exists('unit', $data);
        $identity = $this->productResolver->resolve(
            $job->tenant_id,
            $companyId,
            is_string($data['sku'] ?? null) ? $data['sku'] : null,
            is_string($data['barcode'] ?? null) ? $data['barcode'] : null,
            (string) ($data['name'] ?? ''),
        );
        if ($identity->failure === ProductIdentityFailure::SkuHeldByDeletedProduct) {
            throw new CodedImportRowException(
                ImportErrorCode::SkuHeldByDeletedProduct,
                sprintf(
                    'SKU %s is held by a soft-deleted product; purge the deleted record or choose a different SKU.',
                    $identity->failureSku ?? '',
                ),
                $identity->failureSku === null ? [] : ['sku' => $identity->failureSku],
            );
        }
        if ($identity->isBarcodeAmbiguous()) {
            throw new CodedImportRowException(
                ImportErrorCode::BarcodeAmbiguous,
                'Barcode matches more than one product in this company.',
                ['candidate_skus' => $identity->candidateSkus],
            );
        }

        $existingPriceInputs = $identity->productId === null
            ? null
            : $this->productService->findPriceInputs($job->tenant_id, $companyId, $identity->productId);

        $unit = $this->unitResolver->resolve(
            $companyId,
            is_string($data['unit'] ?? null) ? $data['unit'] : null,
            $identity->productId !== null,
        );
        if ($unit->unitId !== null) {
            $data['unit_id'] = $unit->unitId;
            $data['unit'] = $unit->unitCode;
        }
        $data['type'] = $this->emptyString($data['type'] ?? null) ? ProductType::Part->value : $data['type'];
        $company = $this->resolveCompany($job->tenant_id, $companyId);

        // W2-3: resolve the category BEFORE the upsert so the row can report what
        // happened. The upsert resolves the same name through the same idempotent
        // service and therefore finds this row; nothing is created twice.
        //
        // W2-5 / C-23(iii): it must also come before the tax default. This method
        // pre-sets `tax_rate`, which SATISFIES the category-aware branch inside
        // ProductService::upsert() — so a category carrying its own
        // `default_tax_rate` never reached an imported product while the default
        // here was resolved company-only.
        $category = $this->resolveRowCategory($companyId, $data);
        if ($category !== null) {
            $data['_results'] = ImportRowResultsData::fromStorage($data['_results'] ?? [])
                ->merged(ImportRowResultsData::PHASE_PRODUCT, ['category' => $category->outcome->value])
                ->toStorage();
        }

        $tax = $this->resolveProductTax($company, $data, $category?->categoryId);
        $authority = $this->resolvePriceAuthority($job);
        $price = $this->productPriceResolver->resolve(
            $this->effectivePriceInputs($data, $existingPriceInputs),
            $authority,
            $this->effectivePriceTaxRate($data, $category, $tax->taxRate, $existingPriceInputs),
        );

        if ($price['sale_price'] !== null) {
            $data['sale_price'] = $price['sale_price'];
        }

        if ($this->emptyString($data['tax_rate'] ?? null)) {
            $data['tax_rate'] = $tax->taxRate;

            // Gate r1 F-4: name the LEVEL, not just "not from the file". This is
            // the only breadcrumb the row keeps about where its rate came from,
            // and a flat `default` stopped being true the moment the category
            // became a real source (W2-5) — which is the case this lane exists
            // to fix, so it is the case the breadcrumb must get right.
            $data['_results'] = ImportRowResultsData::fromStorage($data['_results'] ?? [])
                ->merged(ImportRowResultsData::PHASE_PRODUCT, ['tax_source' => $tax->source->value])
                ->toStorage();
        }

        $row->update(['data' => $data]);

        $upsert = $this->productService->upsertWithResult($job->tenant_id, $companyId, $data);
        $productId = $upsert->productId;

        if ($sourceMaskWasCaptured && $identity->matchedBy === ProductIdentityMatch::Name) {
            $this->addRowWarning($row, ImportWarningCode::MatchedByName, 'Matched the existing product by normalized name.');
        }
        if ($unit->warning !== null && $unitWasInSource) {
            $this->addRowWarning($row, $unit->warning, 'No unit was supplied; the product defaulted to pc.');
        }

        foreach ($price['warnings'] as $warning) {
            $this->addRowWarning($row, $warning['code'], $warning['detail']);
        }

        if ($category !== null && $category->outcome->isStateChange()) {
            $this->addRowWarning(
                $row,
                match ($category->outcome) {
                    CategoryResolutionOutcome::Created => ImportWarningCode::CategoryCreated,
                    CategoryResolutionOutcome::Restored => ImportWarningCode::CategoryRestored,
                    CategoryResolutionOutcome::MatchedBySlug => ImportWarningCode::CategoryMatchedBySlug,
                    CategoryResolutionOutcome::Matched => throw new RuntimeException('Matched categories do not emit warnings.'),
                },
                $this->categoryWarningDetail($category, trim((string) $data['category_name'])),
            );
        }

        $this->productPlacementImportService->commitRow($job, $row, $productId);

        return $productId;
    }

    /**
     * Spell out what the resolution actually DID to master data. A restore is not
     * a fresh category: `categories` carries default tax, margin overrides,
     * max discount and restock policy (Product/Domain/Category.php), so bringing
     * a deleted one back re-applies all of it to the imported products.
     */
    private function categoryWarningDetail(CategoryResolutionDTO $category, string $name): string
    {
        return match ($category->outcome) {
            CategoryResolutionOutcome::Created => sprintf(
                'category "%s" did not exist and was created from this row',
                $name,
            ),
            CategoryResolutionOutcome::Restored => sprintf(
                'category "%s" had been deleted and was reactivated from this row, '
                .'together with its existing tax, margin, discount and restock policy',
                $name,
            ),
            CategoryResolutionOutcome::MatchedBySlug => sprintf(
                'category "%s" was linked to the existing category "%s" (same slug); '
                .'if these are meant to be different categories, rename one and re-import',
                $name,
                $category->categoryName,
            ),
            CategoryResolutionOutcome::Matched => sprintf('category "%s" matched', $name),
        };
    }

    /**
     * Resolve a product row's `category_name` to a company category, creating it
     * on a miss (W2-3). Returns null when the row carries no category at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveRowCategory(string $companyId, array $data): ?CategoryResolutionDTO
    {
        $name = trim((string) ($data['category_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return $this->productService->resolveCategoryByName($companyId, $name);
    }

    /**
     * The rate this product row imports at, WITH the level that supplied it.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $categoryId  The category the row resolved to (W2-5) — the
     *                                default ladder is the category's configuration,
     *                                then its rate, then the company rate, so omitting
     *                                it silently flattened every category-specific rate
     *                                to the company default.
     */
    private function resolveProductTax(Company $company, array $data, ?int $categoryId = null): ProductTaxDefaultDTO
    {
        if (! $this->emptyString($data['tax_rate'] ?? null)) {
            /** @var numeric-string $fileRate */
            $fileRate = (string) $data['tax_rate'];

            return new ProductTaxDefaultDTO($fileRate, ProductTaxDefaultSource::File);
        }

        return $this->taxDefaultResolver->resolveDefaultTaxForNewProduct($company, $categoryId);
    }

    /**
     * @return 'ttc'|'ht'|'margin'
     */
    private function resolvePriceAuthority(ImportJob $job): string
    {
        $authority = $job->options['price_authority'] ?? null;

        return in_array($authority, ['ttc', 'ht', 'margin'], true) ? $authority : 'ttc';
    }

    /**
     * Coalesce only persisted derivation dependencies over sparse incoming cells.
     * The stored `sale_price` is the TTC-authoritative output under the precision
     * contract: it is never recycled as a new resolver candidate. With no incoming
     * TTC, HT, or margin cell, the resolver must return no candidate and leave it.
     *
     * @param  array<string, mixed>  $data
     * @param  array{purchase_price: ?string, tax_rate: ?string}|null  $existing
     * @return array<string, mixed>
     */
    private function effectivePriceInputs(array $data, ?array $existing): array
    {
        if ($this->emptyString($data['purchase_price'] ?? null)
            && $existing !== null
            && $existing['purchase_price'] !== null) {
            $data['purchase_price'] = $existing['purchase_price'];
        }

        return $data;
    }

    /**
     * Use the product's persisted rate for a sparse update. An incoming rate or
     * category still governs the rate selected by resolveProductTax().
     *
     * @param  array<string, mixed>  $data
     * @param  array{purchase_price: ?string, tax_rate: ?string}|null  $existing
     */
    private function effectivePriceTaxRate(
        array $data,
        ?CategoryResolutionDTO $category,
        string $resolvedTaxRate,
        ?array $existing,
    ): string {
        if (! $this->emptyString($data['tax_rate'] ?? null) || $category !== null) {
            return $resolvedTaxRate;
        }

        return $existing['tax_rate'] ?? $resolvedTaxRate;
    }

    private function resolveCompany(string $tenantId, string $companyId): Company
    {
        /** @var Company $company */
        $company = Company::where('tenant_id', $tenantId)->findOrFail($companyId);

        return $company;
    }

    /**
     * Stage an opening-balance row.
     *
     * GL opening balances create NO per-row entity: the whole file is posted as
     * one batch-documented, historical journal entry in
     * {@see AccountingBalancesPhase} during finalizeImport(). The row id is
     * returned only so the execution loop can mark the row imported; the phase
     * clears that placeholder and re-links imported_entity_id to the journal
     * entry the row actually landed in.
     */
    private function stageOpeningBalance(ImportRow $row): string
    {
        return $row->id;
    }

    /**
     * Import a composite item row via CompositeItemServiceInterface.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importCompositeItem(string $tenantId, array $data, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();

        return $this->compositeItemService->upsert($tenantId, $companyId, $data);
    }

    /**
     * Apply column mapping to rows — rename source column names to target names.
     * Unmapped columns are dropped.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>|null  $mapping  Source → target column name map
     * @return array<int, array<string, mixed>>
     */
    public function applyColumnMapping(array $rows, ?array $mapping): array
    {
        if ($mapping === null || $mapping === []) {
            foreach ($rows as $rowNumber => $row) {
                $rows[$rowNumber]['_provided'] = $this->providedKeys($row);
            }

            return $rows;
        }

        $mappedRows = [];

        foreach ($rows as $rowNumber => $row) {
            $normalizedRow = [];
            foreach ($row as $source => $value) {
                $normalizedRow[self::normalizeColumnKey($source)] = $value;
            }

            $mapped = [];
            foreach ($mapping as $source => $target) {
                $normalizedSource = self::normalizeColumnKey($source);
                if (array_key_exists($normalizedSource, $normalizedRow)) {
                    $mapped[$target] = $normalizedRow[$normalizedSource];
                }
            }

            $mapped['_provided'] = $this->providedKeys($mapped);

            $mappedRows[$rowNumber] = $mapped;
        }

        return $mappedRows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function providedKeys(array $row): array
    {
        $provided = [];
        foreach ($row as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            if ($value !== null && trim((string) $value) !== '') {
                $provided[] = $key;
            }
        }

        return $provided;
    }

    private function sourceMaskWasCaptured(ImportRow $row): bool
    {
        $raw = $row->getRawOriginal('data');
        if (! is_string($raw)) {
            return false;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_key_exists('_provided', $decoded);
    }

    private static function normalizeColumnKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * Parse CSV file and create rows
     *
     * @return array{headers: array<string>, row_count: int}
     */
    public function parseCsvFile(ImportJob $job, string $content): array
    {
        $lines = explode("\n", trim($content));
        /** @var string $headerLine */
        $headerLine = array_shift($lines);
        $rawHeaders = str_getcsv($headerLine);
        /** @var array<string> $headers */
        $headers = array_map(fn (?string $h) => strtolower(trim($h ?? '')), $rawHeaders);

        $rowNumber = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rowNumber++;
            $values = str_getcsv($line);
            /** @var array<string, mixed> $data */
            $data = array_combine($headers, $values);

            $this->addRow($job, $rowNumber, $data);
        }

        return [
            'headers' => $headers,
            'row_count' => $rowNumber,
        ];
    }
}
