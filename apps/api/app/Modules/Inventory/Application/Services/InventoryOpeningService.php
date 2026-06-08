<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
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
        private readonly ProductCostLock $costLock,
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
     * Creates:
     * 1. Stock movements with movement_type = 'opening', is_historical = true
     * 2. Updates/creates stock levels
     * 3. Updates product cost_price
     * 4. GL entry: Dr. Inventory, Cr. Opening Balance Equity
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

        // Get valid rows only
        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        if ($validRows->isEmpty()) {
            throw new RuntimeException('No valid rows to post. Please validate the batch first.');
        }

        $company = Company::findOrFail($batch->company_id);

        // Multi-product deadlock defense: acquire ALL the batch's product
        // advisory locks UP-FRONT in ONE sorted call (ProductCostLock sorts
        // internally) before the per-row create/recompute loop. A per-iteration
        // acquire([$singleId]) accumulates per-product advisory locks in row
        // order (unsorted) within the one transaction, so two concurrent posts
        // over overlapping products in different row orders AB-BA deadlock;
        // attempts:3 just retries the same losing order. Mirrors
        // StockTransferService::complete/cancel and GoodsReceiptService::receiveGoods.
        $seenProductIds = [];
        foreach ($validRows as $row) {
            $mappedData = $row->mapped_data;

            if (! is_array($mappedData) || ! isset($mappedData['product_id'])) {
                continue;
            }

            $seenProductIds[(string) $mappedData['product_id']] = true;
        }
        /** @var list<string> $productIds */
        $productIds = array_keys($seenProductIds);

        return DB::transaction(function () use ($batch, $validRows, $company, $userId, $productIds): JournalEntry {
            return $this->costLock->acquire($company->tenant_id, $company->id, $productIds, function () use ($batch, $validRows, $company, $userId): JournalEntry {
                $entryNumber = $this->generateEntryNumber($company->id);

                $totalInventoryValue = '0.00';
                $rowEntityMap = [];

                // Process each row: create stock movements and update stock levels.
                // All product advisory locks are already held up-front (sorted), so
                // per-row work runs directly — no nested per-row re-acquire needed.
                foreach ($validRows as $row) {
                    $mappedData = $row->mapped_data;

                    if (! is_array($mappedData) || ! isset($mappedData['product_id'], $mappedData['location_id'])) {
                        continue;
                    }

                    $quantity = $mappedData['quantity'] ?? '0.00';
                    $unitCost = $mappedData['unit_cost'] ?? '0.00';
                    $lineValue = bcmul($quantity, $unitCost, $this->monetaryScale());

                    $productId = (string) $mappedData['product_id'];
                    $locationId = $mappedData['location_id'];

                    // Get or create stock level (with lock for update)
                    $stockLevel = StockLevel::where('product_id', $productId)
                        ->where('location_id', $locationId)
                        ->lockForUpdate()
                        ->first();

                    $quantityBefore = $stockLevel !== null ? $stockLevel->quantity : '0.00';
                    $quantityAfter = bcadd($quantityBefore, $quantity, $this->quantityScale());

                    // Create stock movement
                    $movement = StockMovement::create([
                        'tenant_id' => $company->tenant_id,
                        'company_id' => $company->id,
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'movement_type' => MovementType::Opening,
                        'quantity' => $quantity,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference' => "Opening Balance Batch: {$batch->name}",
                        'notes' => 'Initial inventory from opening balance import',
                        'user_id' => $userId,
                        'is_historical' => true,
                    ]);

                    // Update or create stock level
                    if ($stockLevel !== null) {
                        $stockLevel->update(['quantity' => $quantityAfter]);
                    } else {
                        StockLevel::create([
                            'tenant_id' => $company->tenant_id,
                            'company_id' => $company->id,
                            'product_id' => $productId,
                            'location_id' => $locationId,
                            'quantity' => $quantityAfter,
                            'reserved' => '0.00',
                        ]);
                    }

                    // Update product cost_price (simple replacement for opening - no weighted average calculation needed)
                    if (bccomp($unitCost, '0.00', $this->monetaryScale()) > 0) {
                        Product::where('id', $productId)
                            ->update([
                                'cost_price' => $unitCost,
                                'cost_updated_at' => now(),
                            ]);
                    }

                    $totalInventoryValue = bcadd($totalInventoryValue, $lineValue, $this->monetaryScale());
                    $rowEntityMap[$row->id] = $movement->id;
                }

                // Create GL entry: Dr. Inventory, Cr. Opening Balance Equity
                $inventoryAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Inventory);
                $obeAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::OpeningBalanceEquity);

                $entry = JournalEntry::create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'entry_number' => $entryNumber,
                    'entry_date' => $batch->cutover_date,
                    'description' => "Inventory Opening Balance - {$batch->name}",
                    'status' => JournalEntryStatus::Posted,
                    'source_type' => 'opening_balance',
                    'source_id' => $batch->id,
                    'is_historical' => true,
                    'posted_at' => now(),
                    'posted_by' => $userId,
                ]);

                // Debit: Inventory
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $inventoryAccount->id,
                    'partner_id' => null,
                    'debit' => $totalInventoryValue,
                    'credit' => '0.00',
                    'description' => 'Opening inventory value',
                    'line_order' => 0,
                ]);

                // Credit: Opening Balance Equity
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $obeAccount->id,
                    'partner_id' => null,
                    'debit' => '0.00',
                    'credit' => $totalInventoryValue,
                    'description' => 'Opening Balance Equity offset',
                    'line_order' => 1,
                ]);

                // Mark rows as posted
                $this->batchService->markRowsPosted($rowEntityMap);

                // Mark batch as validated (posted)
                $this->batchService->markBatchValidated($batch, $userId);

                return $entry->load('lines');
            });
        }, attempts: 3);
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

    /**
     * Generate entry number for inventory opening journal entry.
     */
    private function generateEntryNumber(string $companyId): string
    {
        $year = date('Y');
        $lastEntry = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "INV-OB-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry !== null) {
            $lastNumber = (int) substr($lastEntry->entry_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('INV-OB-%s-%06d', $year, $nextNumber);
    }
}
