<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Shared\Contracts\AccountingServiceInterface;
use App\Shared\Contracts\CompositeItemServiceInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\Contracts\ProductServiceInterface;
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
        private readonly InventoryServiceInterface $inventoryService,
        private readonly LocationServiceInterface $locationService,
        private readonly AccountingServiceInterface $accountingService,
        private readonly CompositeItemServiceInterface $compositeItemService
    ) {}

    /**
     * Create a new import job
     *
     * @param  array<string, string>|null  $columnMapping
     */
    public function createJob(
        string $tenantId,
        string $userId,
        ImportType $type,
        string $filename,
        string $filePath,
        int $totalRows,
        ?array $columnMapping = null
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
            'data' => $data,
            'is_valid' => false,
        ]);
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
        $batches = array_chunk($rows, max($batchSize, 1), true);

        foreach ($batches as $batch) {
            $insertData = [];
            foreach ($batch as $rowNumber => $data) {
                $insertData[] = [
                    'id' => (string) Str::uuid(),
                    'import_job_id' => $job->id,
                    'row_number' => $rowNumber,
                    'data' => json_encode($data),
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
     * Get valid rows for an import job
     *
     * @return Collection<int, ImportRow>
     */
    public function getValidRows(ImportJob $job): Collection
    {
        return $job->rows()
            ->where('is_valid', true)
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
        $successCount = 0;
        $executionFailCount = 0;

        foreach ($validRows as $row) {
            try {
                DB::transaction(function () use ($job, $row, &$successCount): void {
                    $entityId = $this->importRow($job, $row);
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
                $executionFailCount++;
            }

            $processedCount++;
            $job->update(['processed_rows' => $processedCount]);
        }

        // Total failed = validation errors + execution errors
        $totalFailedCount = $validationSkippedCount + $executionFailCount;

        // Determine status: CompletedWithErrors if there were any skipped/failed rows
        $status = match (true) {
            $successCount === 0 => ImportStatus::Failed,
            $totalFailedCount > 0 => ImportStatus::Completed, // Partial success
            default => ImportStatus::Completed,
        };

        $job->update([
            'status' => $status,
            'successful_rows' => $successCount,
            'failed_rows' => $totalFailedCount,
            'completed_at' => now(),
        ]);

        return [
            'imported_count' => $successCount,
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
            ImportType::Partners => $this->importPartner($job->tenant_id, $row->data, $companyId),
            ImportType::Products => $this->importProduct($job->tenant_id, $row->data, $companyId),
            ImportType::StockLevels => $this->importStockLevel($job->tenant_id, $row->data, $companyId),
            ImportType::OpeningBalances => $this->importOpeningBalance($job->tenant_id, $row->data, $companyId),
            ImportType::ProductImages => throw new RuntimeException('Product image import is not yet supported'),
            ImportType::CompositeItems => $this->importCompositeItem($job->tenant_id, $row->data, $companyId),
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
     * @param  array<string, mixed>  $data
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importProduct(string $tenantId, array $data, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();

        return $this->productService->upsert($tenantId, $companyId, $data);
    }

    /**
     * Import a stock level row via service interfaces.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importStockLevel(string $tenantId, array $data, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();

        // Find product by SKU
        $productId = $this->productService->findIdBySku($tenantId, $companyId, $data['product_sku']);
        if ($productId === null) {
            throw new RuntimeException("Product with SKU '{$data['product_sku']}' not found");
        }

        // Find location by code
        $locationId = $this->locationService->findIdByCode($companyId, $data['location_code']);
        if ($locationId === null) {
            throw new RuntimeException("Location with code '{$data['location_code']}' not found");
        }

        return $this->inventoryService->upsertStockLevel(
            $tenantId,
            $companyId,
            $productId,
            $locationId,
            (int) $data['quantity']
        );
    }

    /**
     * Import an opening balance row via AccountingServiceInterface.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $companyId  Company ID for async context (null uses CompanyContext)
     */
    private function importOpeningBalance(string $tenantId, array $data, ?string $companyId = null): string
    {
        $companyId ??= $this->companyContext->requireCompanyId();

        // Find account by code
        $accountId = $this->accountingService->findAccountIdByCode($tenantId, $companyId, $data['account_code']);
        if ($accountId === null) {
            throw new RuntimeException("Account with code '{$data['account_code']}' not found");
        }

        /** @var string $description */
        $description = $data['description'] ?? 'Opening Balance';
        /** @var string|null $reference */
        $reference = isset($data['reference']) && $data['reference'] !== '' ? $data['reference'] : null;
        /** @var string $debit */
        $debit = $data['debit'] ?? '0.00';
        /** @var string $credit */
        $credit = $data['credit'] ?? '0.00';

        return $this->accountingService->createOpeningBalanceEntry(
            $tenantId,
            $companyId,
            $accountId,
            $debit,
            $credit,
            $description,
            $reference,
            now()
        );
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

        return array_map(static function (array $row) use ($mapping): array {
            $mapped = [];
            foreach ($mapping as $source => $target) {
                if (array_key_exists($source, $row)) {
                    $mapped[$target] = $row[$source];
                }
            }

            return $mapped;
        }, $rows);
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
