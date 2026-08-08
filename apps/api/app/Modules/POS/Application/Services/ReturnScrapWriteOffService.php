<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The single, compliant SCRAP-disposition write-off for POS returns (DPA V10).
 *
 * WHY THIS EXISTS
 *
 * A POS return is TWO economic acts, and document-per-action says each needs its
 * own justifying document and its own ledger effect:
 *
 *   1. RE-ENTRY  — the return receipt justifies the goods coming back
 *                  (`+qty`, `MovementReason::POSReturn`). Owned by the caller.
 *   2. DESTRUCTION — `disposition = scrap` says the goods are then destroyed.
 *                  A return note does NOT justify destruction; a write-off does.
 *
 * Before V10 leg 2 was a raw `StockMovement::create()` with explicitly no cost,
 * no WAC involvement and no GL — inventory value silently walked off the balance
 * sheet. This service replaces it with the settled write-off idiom already used
 * by `BatchWriteOffService::writeOff()`:
 *
 *   cost-resolved `StockAdjustmentService::issue()`  (persists unit_cost/total_cost
 *   at COST_SCALE=6, emits StockMovementRecorded/V2, stamps the DPA S0 document
 *   linkage) + `GeneralLedgerService::createInventoryWriteOffEntry()` keyed on the
 *   movement id (Dr COGS / Cr Inventory).
 *
 * WHY NOT `BatchWriteOffService` DIRECTLY
 *
 * That service's signature is `writeOff(Batch $batch, ...)` — every step is
 * batch-lot-scoped (it resolves the product FROM the batch, and calls
 * `BatchStockService::issueBatchStock()` to decrement the lot). A POS scrap has
 * no batch by construction: scrapped goods deliberately never re-enter a
 * sellable lot, which is exactly why the SCRAP branch skips batch restitution.
 * Passing a synthetic batch would inflate `inventory_batch_stock`. So V10 reuses
 * the same underlying SEQUENCE rather than the batch-shaped wrapper.
 *
 * CONTEXT SAFETY (house rules 19 + 20)
 *
 * Both callers reach this service from contexts where `CompanyContext` may be
 * unbound (the v4 fiscal projection runs in a queue worker), so the currency is
 * an explicit REQUIRED parameter and every scale resolution goes through
 * `getScale($currencyCode)` — never a bare no-arg `getScale()`.
 */
final class ReturnScrapWriteOffService
{
    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly GeneralLedgerService $glService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Write off the received-back quantity of a SCRAP return line.
     *
     * Returns the write-off movement, or null when the product cannot be
     * resolved in the caller's tenant+company (nothing to cost, nothing to
     * post — the caller's re-entry leg stands alone and is logged).
     *
     * @param  numeric-string  $quantity  POSITIVE magnitude to destroy
     * @param  string  $returnReceiptId  UUID of the RETURN receipt (S0 linkage target)
     * @param  string  $returnReceiptNumber  Human-readable return receipt number (GL narrative)
     * @param  string  $currencyCode  Explicit currency — never resolved from CompanyContext
     * @param  CarbonInterface|null  $occurredAt  Device event time on the projection path; null = now()
     */
    public function writeOff(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $returnReceiptId,
        string $returnReceiptNumber,
        string $currencyCode,
        string $cashierId,
        ?string $variantId = null,
        ?CarbonInterface $occurredAt = null,
    ): ?StockMovement {
        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($productId);

        if ($product === null) {
            Log::warning('ReturnScrapWriteOffService: product not resolvable for scrap write-off; leg skipped', [
                'product_id' => $productId,
                'company_id' => $companyId,
                'return_receipt_id' => $returnReceiptId,
            ]);

            return null;
        }

        // The perpetual WAC at rest (`products.cost_price`, 6 dp). Snapshotting it
        // onto the movement is what lets a later reversal recover the ORIGINAL
        // cost instead of recomputing from a since-changed average — the same
        // single-source-of-truth discipline as BatchWriteOffService.
        /** @var numeric-string $unitCost */
        $unitCost = (string) ($product->cost_price ?? '0.00');

        $movement = $this->stockAdjustmentService->issue(
            productId: $productId,
            locationId: $locationId,
            quantity: $quantity,
            reference: "POS return scrap: {$returnReceiptNumber}",
            userId: $cashierId,
            expectedCompanyId: $companyId,
            variantId: $variantId,
            reason: MovementReason::WriteOff,
            unitCost: $unitCost,
            referenceType: StockMovementReferenceType::PosReceiptReturnScrap,
            referenceId: $returnReceiptId,
            occurredAt: $occurredAt,
        );

        // GL amount at the CURRENCY scale, mirroring BatchWriteOffService's
        // calculateWriteOffAmount() exactly so the two write-off flavours can
        // never disagree about the posted amount for the same movement cost.
        $amount = bcmul($quantity, $unitCost, $this->scaleResolver->getScale($currencyCode));

        try {
            $this->glService->createInventoryWriteOffEntry(
                companyId: $companyId,
                // The `batchNumber` parameter is the free-text source label the GL
                // narrative interpolates; a POS scrap has no lot, so it carries the
                // return receipt number instead. `source_type` stays
                // 'batch_write_off' deliberately — that is the canonical inventory
                // write-off coordinate `reverseInventoryWriteOffEntry()` looks up,
                // so POS scraps inherit reversal support for free.
                batchNumber: "POS-SCRAP {$returnReceiptNumber}",
                productId: $productId,
                amount: $amount,
                reason: MovementReason::WriteOff,
                movementId: $movement->id,
                postedByUserId: $cashierId,
                currencyCode: $currencyCode,
            );
        } catch (\RuntimeException $e) {
            // GL accounts may not be configured for this company — log, never
            // block the return. Same stance as BatchWriteOffService.
            Log::warning('ReturnScrapWriteOffService: could not create GL entry for POS scrap write-off: '.$e->getMessage(), [
                'return_receipt_id' => $returnReceiptId,
                'movement_id' => $movement->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        return $movement;
    }
}
