<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\OpeningLockedException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\InventoryServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

/**
 * Audit-preserving reset for an inventory opening balance.
 *
 * Posts a REVERSING StockMovement (reverses_movement_id set, negated qty) and,
 * when the original had a positive monetary value, a contra JournalEntry
 * (Dr OBE / Cr Inventory, is_historical=true) that brings both the stock level
 * and the GL back to zero. The original opening movement is NEVER mutated — it
 * remains for audit. After the reset, hasActiveOpening() returns false and the
 * product is re-enterable via OpeningBalancePostingService.
 *
 * Guards (acquired under the product cost lock):
 *  - hasActiveOpening MUST be true (nothing to reset if absent)
 *  - hasDownstreamMovements MUST be false (live inventory cannot be rolled back)
 *
 * Both guards firing together prevents two unsafe races:
 *  - Concurrent re-entry after the lock is acquired but before this runs.
 *  - Concurrent issue/receipt that post downstream movements between the guard
 *    check and the reversal write.
 *
 * @throws OpeningLockedException when either guard fails.
 */
final class ResetOpeningBalanceService
{
    public function __construct(
        private readonly ProductCostLock $costLock,
        private readonly InventoryServiceInterface $inventory,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Reset the opening balance for a product, posting a reversing movement
     * and contra GL entry, then clearing the product cost fields.
     *
     * @throws OpeningLockedException when the reset guard fails.
     */
    public function reset(string $companyId, string $tenantId, string $productId, string $userId): void
    {
        /** @var Company $company */
        $company = Company::findOrFail($companyId);
        $monetaryScale = $this->scaleResolver->getScale($company->currency);
        $quantityScale = 4;

        DB::transaction(function () use ($companyId, $tenantId, $productId, $userId, $company, $monetaryScale, $quantityScale): void {
            $this->costLock->acquire(
                $tenantId,
                $companyId,
                [$productId],
                function () use ($companyId, $tenantId, $productId, $userId, $company, $monetaryScale, $quantityScale): void {
                    // Guard: require an active opening AND no downstream movements.
                    if (! $this->inventory->hasActiveOpening($companyId, $productId)
                        || $this->inventory->hasDownstreamMovements($companyId, $productId)) {
                        throw new OpeningLockedException(
                            "Cannot reset opening balance for product {$productId}: "
                            .'no active opening exists or downstream inventory movements are present.'
                        );
                    }

                    // Load the active opening movement.
                    /** @var StockMovement $opening */
                    $opening = StockMovement::query()
                        ->where('company_id', $companyId)
                        ->where('product_id', $productId)
                        ->where('movement_type', MovementType::Opening)
                        ->whereNull('reverses_movement_id')
                        ->whereDoesntHave('reversalOf')
                        ->firstOrFail();

                    // Compute the reversal quantities. The reversal reduces stock to zero.
                    $originalQty = (string) $opening->quantity;
                    $negatedQty = bcmul($originalQty, '-1', $quantityScale);

                    // quantity_after of the original = current stock level before reversal.
                    $quantityBefore = (string) $opening->quantity_after;
                    $quantityAfter = bcadd($quantityBefore, $negatedQty, $quantityScale); // → '0.0000'

                    // Compute the negated total_cost for the reversal movement (at monetary scale).
                    $negatedTotalCost = $opening->total_cost !== null
                        ? bcmul((string) $opening->total_cost, '-1', $monetaryScale)
                        : null;

                    // 1. Post the reversing StockMovement.
                    $reversal = StockMovement::create([
                        'tenant_id' => $tenantId,
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'variant_id' => $opening->variant_id,
                        'location_id' => $opening->location_id,
                        'movement_type' => MovementType::Opening,
                        'reason' => $opening->reason,
                        'quantity' => $negatedQty,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'unit_cost' => $opening->unit_cost !== null ? (string) $opening->unit_cost : null,
                        'total_cost' => $negatedTotalCost,
                        'reference' => 'Opening balance reversal: '.$opening->id,
                        'notes' => null,
                        'user_id' => $userId,
                        'is_historical' => true,
                        'reverses_movement_id' => $opening->id,
                        // The reversal physically happens now (correction time).
                        'occurred_at' => now(),
                    ]);

                    // 2. Update the stock level to zero (lock for update to serialize).
                    $stockLevel = StockLevel::query()
                        ->where('product_id', $productId)
                        ->where('location_id', $opening->location_id)
                        ->lockForUpdate()
                        ->first();

                    if ($stockLevel !== null) {
                        $stockLevel->update(['quantity' => $quantityAfter]);
                    }

                    // 3. Post a contra GL journal entry when the original had a positive value.
                    //    Contra: Dr OpeningBalanceEquity / Cr Inventory
                    //    (mirrors the original's Dr Inventory / Cr OpeningBalanceEquity)
                    if ($opening->total_cost !== null
                        && bccomp((string) $opening->total_cost, '0', $monetaryScale) > 0
                    ) {
                        $totalValue = CurrencyScale::bcformatStrict(
                            (string) $opening->total_cost,
                            $monetaryScale,
                        );
                        $zero = CurrencyScale::bcformatStrict('0', $monetaryScale);

                        $inventoryAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Inventory);
                        $obeAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::OpeningBalanceEquity);

                        $entryNumber = $this->generateReversalEntryNumber($companyId);

                        $entry = JournalEntry::create([
                            'tenant_id' => $tenantId,
                            'company_id' => $companyId,
                            'entry_number' => $entryNumber,
                            'entry_date' => now()->toDateString(),
                            'description' => 'Inventory Opening Balance Reversal — '.$reversal->id,
                            'status' => JournalEntryStatus::Posted,
                            'source_type' => 'inventory_opening_balance_reversal',
                            'source_id' => $reversal->id,
                            'is_historical' => true,
                            'posted_at' => now(),
                            'posted_by' => $userId,
                        ]);

                        // Dr OBE (contra of original Cr OBE).
                        JournalLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id' => $obeAccount->id,
                            'partner_id' => null,
                            'debit' => $totalValue,
                            'credit' => $zero,
                            'description' => 'Opening Balance Equity reversal',
                            'line_order' => 0,
                        ]);

                        // Cr Inventory (contra of original Dr Inventory).
                        JournalLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id' => $inventoryAccount->id,
                            'partner_id' => null,
                            'debit' => $zero,
                            'credit' => $totalValue,
                            'description' => 'Inventory opening value reversal',
                            'line_order' => 1,
                        ]);
                    }

                    // 4. Clear the product cost fields (cost is unknown until re-entered).
                    Product::where('id', $productId)->update([
                        'cost_price' => CurrencyScale::bcformatStrict('0', 6),
                        'cost_updated_at' => null,
                    ]);

                    // 5. Dispatch StockMovementRecorded events after the transaction commits.
                    //    Both V1 and V2 are fired (dual-dispatch contract — events are immutable).
                    $reversalSnapshot = $reversal;
                    $quantityAfterSnapshot = $quantityAfter;
                    $tenantIdSnapshot = $tenantId;
                    $companyIdSnapshot = $companyId;

                    DB::afterCommit(static function () use (
                        $reversalSnapshot,
                        $tenantIdSnapshot,
                        $companyIdSnapshot,
                        $quantityAfterSnapshot,
                    ): void {
                        event(new StockMovementRecorded(
                            movementId: $reversalSnapshot->id,
                            tenantId: $tenantIdSnapshot,
                            companyId: $companyIdSnapshot,
                            productId: $reversalSnapshot->product_id,
                            locationId: $reversalSnapshot->location_id,
                            movementType: MovementType::Opening->value,
                            quantity: (string) $reversalSnapshot->quantity,
                            unitCost: (string) ($reversalSnapshot->unit_cost ?? '0.000'),
                            totalCost: (string) ($reversalSnapshot->total_cost ?? '0.000'),
                            newStockLevel: $quantityAfterSnapshot,
                            reference: $reversalSnapshot->reference,
                            occurredAt: now()->toIso8601String(),
                        ));

                        event(new StockMovementRecordedV2(
                            movementId: $reversalSnapshot->id,
                            tenantId: $tenantIdSnapshot,
                            companyId: $companyIdSnapshot,
                            productId: $reversalSnapshot->product_id,
                            locationId: $reversalSnapshot->location_id,
                            movementType: MovementType::Opening->value,
                            quantity: (string) $reversalSnapshot->quantity,
                            unitCost: (string) ($reversalSnapshot->unit_cost ?? '0.000'),
                            totalCost: (string) ($reversalSnapshot->total_cost ?? '0.000'),
                            newStockLevel: $quantityAfterSnapshot,
                            variantId: $reversalSnapshot->variant_id,
                            reference: $reversalSnapshot->reference,
                            occurredAt: now()->toIso8601String(),
                        ));
                    });
                }
            );
        }, attempts: 3);
    }

    /**
     * Generate a concurrency-safe INV-OBR-{year}-{seq} entry number for opening
     * balance reversals. Must be called INSIDE an open DB::transaction.
     *
     * Uses a transaction-scoped advisory lock (pgsql only) to prevent the TOCTOU
     * race between the max-read and the insert — same pattern as
     * {@see OpeningBalancePostingService::generateOpeningEntryNumber()}.
     */
    private function generateReversalEntryNumber(string $companyId): string
    {
        $year = date('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ["inv-obr-seq:{$companyId}:{$year}"],
            );
        }

        $lastEntry = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('entry_number', 'like', "INV-OBR-{$year}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry !== null) {
            $lastNumber = (int) substr((string) $lastEntry->entry_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('INV-OBR-%s-%06d', $year, $nextNumber);
    }
}
