<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePostingResult;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\OpeningAlreadyExistsException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared use case for posting an inventory opening balance.
 *
 * Creates, for each line:
 *   1. A StockMovement (type=Opening, reason=OpeningBalance, is_historical=true)
 *   2. A StockLevel row (or update if it exists)
 *   3. A product cost_price update (if unit_cost > 0)
 *
 * And ONE balanced GL journal entry across all lines:
 *   Dr. Inventory  /  Cr. Opening Balance Equity  =  Σ (qty × unit_cost)
 *
 * Enter-once guarantee: throws OpeningAlreadyExistsException if an active
 * (non-reversed) opening movement already exists for the same product+location.
 *
 * Both the import batch adapter (Task 3) and the product-create flow share
 * this service. Batch-specific bookkeeping (markRowsPosted etc.) stays in the
 * import adapter.
 */
final class OpeningBalancePostingService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ProductCostLock $costLock,
        private readonly BatchStockService $batchStockService,
    ) {}

    public function post(OpeningBalancePosting $posting): OpeningBalancePostingResult
    {
        /** @var Company $company */
        $company = Company::findOrFail($posting->companyId);
        $monetaryScale = $this->scaleResolver->getScale($company->currency);
        $quantityScale = 4;

        $productIds = array_values(array_unique(
            array_map(static fn (OpeningBalanceLine $line): string => $line->productId, $posting->lines),
        ));

        // Resolve products up front for the default-batch invariant: a
        // batch-tracked product's opening stock must land inside a lot.
        /** @var Collection<int, Product> $products */
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        /** @var OpeningBalancePostingResult $result */
        $result = DB::transaction(function () use ($posting, $company, $monetaryScale, $quantityScale, $productIds, $products): OpeningBalancePostingResult {
            return $this->costLock->acquire(
                $posting->tenantId,
                $posting->companyId,
                $productIds,
                function () use ($posting, $company, $monetaryScale, $quantityScale, $products): OpeningBalancePostingResult {
                    $entryNumber = $this->generateOpeningEntryNumber($posting->companyId);

                    $totalInventoryValue = '0';
                    /** @var list<string> $movementIds */
                    $movementIds = [];

                    foreach ($posting->lines as $line) {
                        // Enter-once check: reject if an active (non-reversed) opening exists.
                        $activeOpening = StockMovement::query()
                            ->where('company_id', $posting->companyId)
                            ->where('product_id', $line->productId)
                            ->where('location_id', $line->locationId)
                            ->where('movement_type', MovementType::Opening)
                            ->whereNull('reverses_movement_id')
                            ->whereDoesntHave('reversalOf')
                            ->exists();

                        if ($activeOpening) {
                            throw new OpeningAlreadyExistsException(
                                "Active opening already exists for product {$line->productId} at location {$line->locationId}",
                            );
                        }

                        $lineValue = bcmul($line->quantity, $line->unitCost, $monetaryScale);

                        // Lock the stock level row for this product+location.
                        $stockLevel = StockLevel::where('product_id', $line->productId)
                            ->where('location_id', $line->locationId)
                            ->lockForUpdate()
                            ->first();

                        // $stockLevel->quantity is numeric-string (decimal:4 cast).
                        // '0.0000' is a numeric-string literal — no bcformatStrict needed here.
                        $quantityBefore = $stockLevel !== null ? $stockLevel->quantity : '0.0000';
                        $quantityAfter = bcadd($quantityBefore, $line->quantity, $quantityScale);

                        // Create the opening stock movement.
                        $movement = StockMovement::create([
                            'tenant_id' => $posting->tenantId,
                            'company_id' => $posting->companyId,
                            'product_id' => $line->productId,
                            'variant_id' => $line->variantId,
                            'location_id' => $line->locationId,
                            'movement_type' => MovementType::Opening,
                            'reason' => MovementReason::OpeningBalance,
                            'quantity' => $line->quantity,
                            'quantity_before' => $quantityBefore,
                            'quantity_after' => $quantityAfter,
                            'unit_cost' => $line->unitCost,
                            'total_cost' => $lineValue,
                            'reference' => $posting->reference,
                            'notes' => $posting->notes,
                            'user_id' => $posting->userId,
                            'is_historical' => $posting->isHistorical,
                            'occurred_at' => $posting->entryDate,
                        ]);

                        // Update or create the stock level.
                        if ($stockLevel !== null) {
                            $stockLevel->update(['quantity' => $quantityAfter]);
                        } else {
                            StockLevel::create([
                                'tenant_id' => $posting->tenantId,
                                'company_id' => $posting->companyId,
                                'product_id' => $line->productId,
                                'location_id' => $line->locationId,
                                'quantity' => $quantityAfter,
                                'reserved' => CurrencyScale::bcformatStrict('0', $quantityScale),
                            ]);
                        }

                        // Default-batch invariant: a batch-tracked product must
                        // never hold stock that isn't inside a lot. Back the
                        // opened quantity with a DEFAULT lot (expiry = entry date
                        // + the product's default expiry period) so PO receipt,
                        // transfers and POS lot selection all have a lot to pick.
                        $product = $products->get($line->productId);
                        if ($product !== null && $product->requires_batch_tracking) {
                            // Gate r1 finding 12 — `ensureDefaultBatch()` sets the
                            // lot TO the target, it does not add to it, so passing
                            // the LINE quantity was only correct while the DEFAULT
                            // lot was empty. Passing the post-opening aggregate
                            // through the remainder helper is correct
                            // unconditionally: it subtracts whatever real lots
                            // already hold, so it can neither under-seed a second
                            // opening nor double-book stock that arrived in a lot.
                            $this->batchStockService->ensureDefaultBatchForUntrackedRemainder(
                                companyId: $posting->companyId,
                                tenantId: $posting->tenantId,
                                productId: $line->productId,
                                locationId: $line->locationId,
                                aggregateQuantity: $quantityAfter,
                                shelfLifeDays: $product->default_shelf_life_days,
                                asOfDate: $posting->entryDate->toDateString(),
                                variantId: $line->variantId,
                            );
                        }

                        // Stamp the product's cost_price when unit_cost is positive.
                        if (bccomp($line->unitCost, '0', $monetaryScale) > 0) {
                            Product::where('id', $line->productId)
                                ->update([
                                    'cost_price' => $line->unitCost,
                                    'cost_updated_at' => now(),
                                ]);
                        }

                        $totalInventoryValue = bcadd($totalInventoryValue, $lineValue, $monetaryScale);
                        $movementIds[] = $movement->id;

                        // Schedule post-commit event dispatch per movement.
                        // Snapshots are captured by value so each closure gets its own copy.
                        $movementSnapshot = $movement;
                        $quantityAfterSnapshot = $quantityAfter;
                        $tenantIdSnapshot = $posting->tenantId;
                        $companyIdSnapshot = $posting->companyId;

                        DB::afterCommit(static function () use (
                            $movementSnapshot,
                            $tenantIdSnapshot,
                            $companyIdSnapshot,
                            $quantityAfterSnapshot,
                        ): void {
                            event(new StockMovementRecorded(
                                movementId: $movementSnapshot->id,
                                tenantId: $tenantIdSnapshot,
                                companyId: $companyIdSnapshot,
                                productId: $movementSnapshot->product_id,
                                locationId: $movementSnapshot->location_id,
                                movementType: MovementType::Opening->value,
                                quantity: (string) $movementSnapshot->quantity,
                                unitCost: (string) ($movementSnapshot->unit_cost ?? '0.000'),
                                totalCost: (string) ($movementSnapshot->total_cost ?? '0.000'),
                                newStockLevel: $quantityAfterSnapshot,
                                reference: $movementSnapshot->reference,
                                occurredAt: now()->toIso8601String(),
                            ));

                            event(new StockMovementRecordedV2(
                                movementId: $movementSnapshot->id,
                                tenantId: $tenantIdSnapshot,
                                companyId: $companyIdSnapshot,
                                productId: $movementSnapshot->product_id,
                                locationId: $movementSnapshot->location_id,
                                movementType: MovementType::Opening->value,
                                quantity: (string) $movementSnapshot->quantity,
                                unitCost: (string) ($movementSnapshot->unit_cost ?? '0.000'),
                                totalCost: (string) ($movementSnapshot->total_cost ?? '0.000'),
                                newStockLevel: $quantityAfterSnapshot,
                                variantId: $movementSnapshot->variant_id,
                                reference: $movementSnapshot->reference,
                                occurredAt: now()->toIso8601String(),
                            ));
                        });
                    }

                    // GL entry: Dr. Inventory / Cr. Opening Balance Equity.
                    $inventoryAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Inventory);
                    $obeAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::OpeningBalanceEquity);

                    $entry = JournalEntry::create([
                        'tenant_id' => $posting->tenantId,
                        'company_id' => $posting->companyId,
                        'entry_number' => $entryNumber,
                        'entry_date' => $posting->entryDate,
                        'description' => "Inventory Opening Balance - {$posting->reference}",
                        'status' => JournalEntryStatus::Posted,
                        'source_type' => $posting->sourceType,
                        'source_id' => $posting->sourceId,
                        'is_historical' => $posting->isHistorical,
                        'posted_at' => now(),
                        'posted_by' => $posting->userId,
                    ]);

                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $inventoryAccount->id,
                        'partner_id' => null,
                        'debit' => $totalInventoryValue,
                        'credit' => CurrencyScale::bcformatStrict('0', $monetaryScale),
                        'description' => 'Opening inventory value',
                        'line_order' => 0,
                    ]);

                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $obeAccount->id,
                        'partner_id' => null,
                        'debit' => CurrencyScale::bcformatStrict('0', $monetaryScale),
                        'credit' => $totalInventoryValue,
                        'description' => 'Opening Balance Equity offset',
                        'line_order' => 1,
                    ]);

                    return new OpeningBalancePostingResult($entry->load('lines'), $movementIds);
                },
            );
        }, attempts: 3);

        return $result;
    }

    /**
     * Generate a concurrency-safe INV-OB-{year}-{seq} entry number.
     *
     * Must be called INSIDE an open DB::transaction. On PostgreSQL, acquires a
     * transaction-scoped advisory lock keyed on (companyId, year) to prevent the
     * TOCTOU race between the read-max and the insert. On SQLite (test runner)
     * the advisory lock is skipped — concurrency is not meaningful there.
     */
    public function generateOpeningEntryNumber(string $companyId): string
    {
        $year = date('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ["inv-ob-seq:{$companyId}:{$year}"],
            );
        }

        $lastEntry = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "INV-OB-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry !== null) {
            $lastNumber = (int) substr((string) $lastEntry->entry_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('INV-OB-%s-%06d', $year, $nextNumber);
    }
}
