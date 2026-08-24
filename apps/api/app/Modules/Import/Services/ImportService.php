<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Shared\Contracts\CompositeItemServiceInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\ProductServiceInterface;
use App\Shared\Contracts\TaxDefaultResolverInterface;
use App\Shared\Enums\CategoryResolutionOutcome;
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
        private readonly ProductServiceInterface $productService,
        private readonly CompositeItemServiceInterface $compositeItemService,
        private readonly NumericFieldNormalizer $numericNormalizer,
        private readonly PartiesRowMapper $partiesRowMapper,
        private readonly PartiesBalancesPhase $partiesBalancesPhase,
        private readonly AccountingBalancesPhase $accountingBalancesPhase,
        private readonly ProductPriceResolver $productPriceResolver,
        private readonly TaxDefaultResolverInterface $taxDefaultResolver,
        private readonly ProductOpeningStockPhase $productOpeningStockPhase,
        private readonly ProductPlacementImportService $productPlacementImportService,
    ) {}

    /**
     * Create a new import job
     *
     * @param  array<string, string>|null  $columnMapping
     * @param  array<string, string|bool>|null  $options
     */
    public function createJob(
        string $tenantId,
        string $userId,
        ImportType $type,
        string $filename,
        string $filePath,
        int $totalRows,
        ?array $columnMapping = null,
        ?array $options = null
    ): ImportJob {
        return ImportJob::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => $type,
            'status' => ImportStatus::Pending,
            'original_filename' => $filename,
            'file_path' => $filePath,
            'total_rows' => $totalRows,
            'column_mapping' => $columnMapping,
            'options' => $options,
        ]);
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
    public function addRowWarning(ImportRow $row, string $code, string $detail): void
    {
        $warnings = $row->warnings ?? [];
        $warnings[] = ['code' => $code, 'detail' => $detail];
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
        $validCount = 0;
        $invalidCount = 0;

        // Process in chunks to avoid loading all rows into memory
        $job->rows()
            ->orderBy('row_number')
            ->chunk(500, function ($rows) use ($rules, $job, &$validCount, &$invalidCount): void {
                $updates = [];

                foreach ($rows as $row) {
                    $result = $this->validationEngine->validate(
                        $row->data,
                        $rules,
                        $job->tenant_id
                    );

                    $updates[] = [
                        'id' => $row->id,
                        'is_valid' => $result['is_valid'],
                        'errors' => $result['errors'] ?: null,
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
            'status' => ImportStatus::Validated,
            'successful_rows' => $validCount,
            'failed_rows' => $invalidCount,
        ]);

        if ($job->type === ImportType::Parties) {
            $this->applyPartiesExtraValidation($job);
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
                    ]);
                }
            });

        $job->update([
            'successful_rows' => $job->rows()->where('is_valid', true)->count(),
            'failed_rows' => $job->rows()->where('is_valid', false)->count(),
        ]);
    }

    /**
     * Batch update validation results using raw SQL for efficiency.
     *
     * @param  array<array{id: string, is_valid: bool, errors: array<string, array<string>>|null}>  $updates
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
     * @return array{imported_count: int, skipped_count: int, execution_error_count: int, total_rows: int}
     */
    public function executeImport(ImportJob $job): array
    {
        if (! $job->canStart()) {
            throw new RuntimeException('Import cannot be started. No valid rows to import.');
        }

        $job->update([
            'status' => ImportStatus::Importing,
            'started_at' => now(),
        ]);

        // Count validation-skipped rows (invalid from validation phase)
        $validationSkippedCount = $job->rows()->where('is_valid', false)->count();

        $validRows = $this->getValidRows($job);
        $processedCount = 0;
        $executionFailCount = 0;

        foreach ($validRows as $row) {
            try {
                DB::transaction(function () use ($job, $row): void {
                    $entityId = $this->importRow($job, $row);
                    $row->update([
                        'is_imported' => true,
                        'imported_entity_id' => $entityId,
                    ]);
                });
            } catch (\Throwable $e) {
                $row->update([
                    'is_imported' => false,
                    'import_error' => $e->getMessage(),
                ]);
                $executionFailCount++;
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
        $importedCount = $job->rows()->where('is_imported', true)->count();
        $totalFailedCount = $job->rows()->where('is_imported', false)->count();
        $status = $importedCount === 0 ? ImportStatus::Failed : ImportStatus::Completed;

        $job->update([
            'status' => $status,
            'successful_rows' => $importedCount,
            'failed_rows' => $totalFailedCount,
            'completed_at' => now(),
        ]);

        return [
            'imported_count' => $importedCount,
            'skipped_count' => $validationSkippedCount,
            'execution_error_count' => $executionFailCount,
            'total_rows' => $job->total_rows,
        ];
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
        $data = $row->data;
        if ($this->hasAnyBalanceColumn($data) && $this->emptyString($data['code'] ?? null)) {
            $data['code'] = 'IMP-'.substr($job->id, 0, 8).'-'.$row->row_number;
            $row->update(['data' => $data]);
        }

        return $this->importPartner($job->tenant_id, $this->partiesRowMapper->toPartnerData($data), $companyId);
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

            if ($result['code'] !== '') {
                $this->addRowWarning($row, $result['code'], $result['detail']);
            }

            $data = $row->refresh()->data;
            $data['_results'] = array_merge($data['_results'] ?? [], $result['results']);
            $row->update(['data' => $data]);
        }
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
        $data['type'] = $this->emptyString($data['type'] ?? null) ? ProductType::Part->value : $data['type'];
        $company = $this->resolveCompany($job->tenant_id, $companyId);
        $taxRate = $this->resolveProductTaxRate($company, $data);
        $authority = $this->resolvePriceAuthority($job);
        $price = $this->productPriceResolver->resolve($data, $authority, $taxRate);

        if ($price['sale_price'] !== null) {
            $data['sale_price'] = $price['sale_price'];
        }

        if ($this->emptyString($data['tax_rate'] ?? null)) {
            $data['tax_rate'] = $taxRate;
            $data['_results'] = array_merge($data['_results'] ?? [], ['tax_source' => 'default']);
        }

        // W2-3: resolve the category BEFORE the upsert so the row can report what
        // happened. The upsert resolves the same name through the same idempotent
        // service and therefore finds this row; nothing is created twice.
        $categoryOutcome = $this->resolveRowCategory($companyId, $data);
        if ($categoryOutcome !== null) {
            $data['_results'] = array_merge($data['_results'] ?? [], ['category' => $categoryOutcome->value]);
        }

        $row->update(['data' => $data]);

        $productId = $this->productService->upsert($job->tenant_id, $companyId, $data);

        foreach ($price['warnings'] as $warning) {
            $this->addRowWarning($row, $warning['code'], $warning['detail']);
        }

        if ($categoryOutcome !== null && $categoryOutcome->isStateChange()) {
            $this->addRowWarning(
                $row,
                'category_'.$categoryOutcome->value,
                sprintf(
                    'category "%s" did not exist and was %s from this row',
                    trim((string) $data['category_name']),
                    $categoryOutcome->value,
                ),
            );
        }

        $this->productPlacementImportService->commitRow($job, $row, $productId);

        return $productId;
    }

    /**
     * Resolve a product row's `category_name` to a company category, creating it
     * on a miss (W2-3). Returns null when the row carries no category at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveRowCategory(string $companyId, array $data): ?CategoryResolutionOutcome
    {
        $name = trim((string) ($data['category_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return $this->productService->resolveCategoryByName($companyId, $name)->outcome;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveProductTaxRate(Company $company, array $data): string
    {
        if (! $this->emptyString($data['tax_rate'] ?? null)) {
            return (string) $data['tax_rate'];
        }

        return $this->taxDefaultResolver->getDefaultTaxForNewProduct($company);
    }

    /**
     * @return 'ttc'|'ht'|'margin'
     */
    private function resolvePriceAuthority(ImportJob $job): string
    {
        $authority = $job->options['price_authority'] ?? null;

        return in_array($authority, ['ttc', 'ht', 'margin'], true) ? $authority : 'ttc';
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

            $mappedRows[$rowNumber] = $mapped;
        }

        return $mappedRows;
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
