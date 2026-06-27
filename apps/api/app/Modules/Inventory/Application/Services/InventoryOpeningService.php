<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Service for handling Inventory Opening Balance imports.
 *
 * This service manages the import, validation, and posting of
 * opening inventory balances (initial stock) from a previous system.
 *
 * Key features:
 * - Validates product SKUs exist
 * - Validates location codes exist
 * - Validates quantities are positive
 * - Creates stock movements with movement_type = 'opening'
 * - Updates stock levels
 * - Updates product cost_price (weighted average)
 * - Creates GL entry: Dr. Inventory, Cr. Opening Balance Equity
 */
class InventoryOpeningService
{
    public function __construct(
        private readonly OpeningBalanceBatchService $batchService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly OpeningBalancePostingService $postingService,
    ) {}

    private function monetaryScale(): int
    {
        return $this->scaleResolver->getScale();
    }

    private function quantityScale(): int
    {
        return 4;
    }

    /**
     * Validate all import rows in an inventory opening batch.
     *
     * @return array<string, mixed>
     */
    public function validateBatch(OpeningBalanceBatch $batch): array
    {
        if ($batch->type !== OpeningBatchType::Inventory) {
            throw new RuntimeException('This service only handles INVENTORY batch types.');
        }

        $rows = $batch->rows()->where('status', '!=', OpeningImportRowStatus::Skipped)->get();
        $validationResults = [];
        $errors = [];

        $totalValue = '0.00';

        foreach ($rows as $row) {
            $result = $this->validateRow($row, $batch->company_id);
            $validationResults[$row->id] = $result;

            if ($result['valid']) {
                // Calculate line value: quantity * unit_cost
                $lineValue = bcmul(
                    $result['mapped_data']['quantity'] ?? '0.00',
                    $result['mapped_data']['unit_cost'] ?? '0.00',
                    $this->monetaryScale()
                );
                $totalValue = bcadd($totalValue, $lineValue, $this->monetaryScale());
            } else {
                $errors[$row->id] = $result['errors'];
            }
        }

        // Apply validation results to rows
        $this->batchService->applyValidationResults($batch, $validationResults);

        $validCount = count(array_filter($validationResults, fn (array $r): bool => $r['valid']));
        $invalidCount = count($validationResults) - $validCount;

        return [
            'valid' => $invalidCount === 0 && $validCount > 0,
            'total_rows' => count($rows),
            'valid_rows' => $validCount,
            'invalid_rows' => $invalidCount,
            'total_value' => $totalValue,
            'errors' => $errors,
        ];
    }

    /**
     * Validate a single import row.
     *
     * Expected raw_data format:
     * {
     *   "product_code": "SKU-001",
     *   "location_code": "MAIN",
     *   "quantity": "100.00",
     *   "unit_cost": "25.50"
     * }
     *
     * @return array{valid: bool, errors: array<string, array<string>>, mapped_data: array<string, mixed>}
     */
    private function validateRow(OpeningBalanceImportRow $row, string $companyId): array
    {
        $rawData = $row->raw_data;
        $errors = [];
        $mappedData = [];

        // Validate product code
        if (! isset($rawData['product_code']) || $rawData['product_code'] === '') {
            $errors['product_code'] = ['Product code/SKU is required'];
        } else {
            $product = Product::forCompany($companyId)
                ->where('sku', $rawData['product_code'])
                ->active()
                ->first();

            if ($product === null) {
                $errors['product_code'] = ["Product '{$rawData['product_code']}' not found or inactive"];
            } else {
                $mappedData['product_id'] = $product->id;
                $mappedData['product_sku'] = $product->sku;
                $mappedData['product_name'] = $product->name;
            }
        }

        // Validate location code
        if (! isset($rawData['location_code']) || $rawData['location_code'] === '') {
            $errors['location_code'] = ['Location code is required'];
        } else {
            $location = Location::forCompany($companyId)
                ->where('code', $rawData['location_code'])
                ->where('is_active', true)
                ->first();

            if ($location === null) {
                $errors['location_code'] = ["Location '{$rawData['location_code']}' not found or inactive"];
            } else {
                $mappedData['location_id'] = $location->id;
                $mappedData['location_code'] = $location->code;
                $mappedData['location_name'] = $location->name;
            }
        }

        // Validate quantity
        $quantity = $rawData['quantity'] ?? '0.00';
        if (! is_numeric($quantity) || bccomp((string) $quantity, '0.00', $this->quantityScale()) <= 0) {
            $errors['quantity'] = ['Quantity must be a positive number'];
        } else {
            $mappedData['quantity'] = bcadd('0.00', (string) $quantity, $this->quantityScale());
        }

        // Validate unit cost
        $unitCost = $rawData['unit_cost'] ?? '0.00';
        if (! is_numeric($unitCost) || bccomp((string) $unitCost, '0.00', $this->monetaryScale()) < 0) {
            $errors['unit_cost'] = ['Unit cost must be a non-negative number'];
        } else {
            $mappedData['unit_cost'] = bcadd('0.00', (string) $unitCost, $this->monetaryScale());
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mapped_data' => $mappedData,
        ];
    }

    /**
     * Post a validated inventory opening batch.
     *
     * Delegates the movement/stock-level/cost/GL work to OpeningBalancePostingService,
     * then handles the import-specific bookkeeping (markBatchValidated + markRowsPosted).
     *
     * @throws RuntimeException If batch is not validated or has errors
     */
    public function postBatch(OpeningBalanceBatch $batch, string $userId): JournalEntry
    {
        if ($batch->type !== OpeningBatchType::Inventory) {
            throw new RuntimeException('This service only handles INVENTORY batch types.');
        }

        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Cannot post batch in {$batch->status->label()} status. Batch must be in draft status."
            );
        }

        // Get valid rows only, ordered for deterministic movement-ID zipping.
        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        if ($validRows->isEmpty()) {
            throw new RuntimeException('No valid rows to post. Please validate the batch first.');
        }

        $company = Company::findOrFail($batch->company_id);
        $monetaryScale = $this->scaleResolver->getScale($company->currency);

        // Build one OpeningBalanceLine per valid row, tracking contributing rows
        // in the same order so the returned movement IDs can be zipped back.
        /** @var list<OpeningBalanceLine> $lines */
        $lines = [];
        /** @var list<OpeningBalanceImportRow> $lineRows */
        $lineRows = [];

        foreach ($validRows as $row) {
            $mappedData = $row->mapped_data;

            if (! is_array($mappedData) || ! isset($mappedData['product_id'], $mappedData['location_id'])) {
                continue;
            }

            $lines[] = OpeningBalanceLine::make(
                productId: (string) $mappedData['product_id'],
                variantId: null,
                locationId: (string) $mappedData['location_id'],
                quantity: (string) ($mappedData['quantity'] ?? '0.0000'),
                unitCost: (string) ($mappedData['unit_cost'] ?? '0.000'),
                currencyScale: $monetaryScale,
            );

            $lineRows[] = $row;
        }

        $posting = new OpeningBalancePosting(
            tenantId: $company->tenant_id,
            companyId: $company->id,
            userId: $userId,
            entryDate: $batch->cutover_date,
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $batch->id,
            reference: "Opening Balance Batch: {$batch->name}",
            notes: 'Initial inventory from opening balance import',
            lines: $lines,
        );

        return DB::transaction(function () use ($posting, $batch, $lineRows, $userId): JournalEntry {
            $result = $this->postingService->post($posting);

            // Zip the contributing rows (same order as $lines) with the returned movement IDs.
            $rowEntityMap = [];

            foreach ($lineRows as $i => $row) {
                if (isset($result->movementIdsInInputOrder[$i])) {
                    $rowEntityMap[$row->id] = $result->movementIdsInInputOrder[$i];
                }
            }

            // Mark batch validated BEFORE marking rows posted.
            // markBatchValidated checks valid row count, which would be 0
            // after markRowsPosted flips all rows to Posted. Matches the
            // ordering used in AccountingOpeningService::postBatch.
            $this->batchService->markBatchValidated($batch, $userId);

            // Mark rows as posted with their mapped movement IDs.
            $this->batchService->markRowsPosted($rowEntityMap);

            return $result->entry;
        });
    }

    /**
     * Get a preview of what will be posted.
     *
     * @return array<string, mixed>
     */
    public function getPostPreview(OpeningBalanceBatch $batch): array
    {
        if ($batch->type !== OpeningBatchType::Inventory) {
            throw new RuntimeException('This service only handles INVENTORY batch types.');
        }

        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        $lines = $validRows->map(function (OpeningBalanceImportRow $row): array {
            $mappedData = $row->mapped_data;

            $quantity = $mappedData['quantity'] ?? '0.00';
            $unitCost = $mappedData['unit_cost'] ?? '0.00';
            $lineValue = bcmul($quantity, $unitCost, $this->monetaryScale());

            return [
                'row_number' => $row->row_number,
                'product_sku' => $mappedData['product_sku'] ?? 'N/A',
                'product_name' => $mappedData['product_name'] ?? 'N/A',
                'location_code' => $mappedData['location_code'] ?? 'N/A',
                'location_name' => $mappedData['location_name'] ?? 'N/A',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_value' => $lineValue,
            ];
        });

        /** @var string $totalValue */
        $totalValue = $lines->reduce(
            /** @phpstan-ignore argument.type (array value is always numeric-string) */
            fn (string $carry, array $line): string => bcadd($carry, $line['line_value'], $this->monetaryScale()),
            '0.00'
        );

        /** @var string $totalQuantity */
        $totalQuantity = $lines->reduce(
            fn (string $carry, array $line): string => bcadd($carry, $line['quantity'], $this->quantityScale()),
            '0.00'
        );

        return [
            'batch' => [
                'cutover_date' => $batch->cutover_date->toDateString(),
                'description' => "Inventory Opening Balance - {$batch->name}",
                'is_historical' => true,
                'source_type' => 'opening_balance',
            ],
            'lines' => $lines,
            'totals' => [
                'total_lines' => $lines->count(),
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
            ],
            'gl_entry' => [
                'debit_account' => 'Inventory',
                'credit_account' => 'Opening Balance Equity',
                'amount' => $totalValue,
            ],
        ];
    }
}
