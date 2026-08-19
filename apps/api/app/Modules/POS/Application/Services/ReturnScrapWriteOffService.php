<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Exceptions\ScrapWriteOffUnresolvableException;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 *   linkage) + a buffered movement-keyed Dr Shrinkage / Cr Inventory entry.
 *
 * THE PAIR IS ATOMIC — IT NEVER DECLINES QUIETLY (gate C1)
 *
 * Every failure path THROWS. It must: the re-entry leg lives in the caller, so a
 * silent `return null` here leaves the caller's savepoint committing a bare
 * `+qty` restock of destroyed goods. Callers wrap `restore + writeOff` in one
 * savepoint and treat any throw as "record neither leg".
 *
 * WHY NOT `BatchWriteOffService` DIRECTLY
 *
 * That service's signature is `writeOff(Batch $batch, ...)` and every step is
 * batch-lot-scoped: it resolves the product FROM the batch and calls
 * `BatchStockService::issueBatchStock()`, which DECREMENTS the lot and throws
 * `InsufficientBatchStockException` when the lot cannot cover the quantity. A POS
 * scrap has no batch by construction — scrapped goods deliberately never re-enter
 * a sellable lot, which is exactly why the SCRAP branch skips batch restitution.
 * Passing a synthetic lot would therefore DEFLATE (or fail on) a lot that never
 * received the goods, driving `SUM(inventory_batch_stock)` below the aggregate.
 * So V10 reuses the same underlying SEQUENCE rather than the batch-shaped wrapper.
 *
 * CONTEXT SAFETY (house rules 19 + 20)
 *
 * Both callers reach this service from contexts where `CompanyContext` may be
 * unbound (the v4 fiscal projection runs in a queue worker), so the currency is
 * an explicit REQUIRED parameter. The terminal posting seam resolves its scale
 * from that explicit code — never from a no-arg CompanyContext lookup.
 */
final class ReturnScrapWriteOffService
{
    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly InventoryGlPostingBuffer $glBuffer,
    ) {}

    /**
     * Write off the received-back quantity of a SCRAP return line.
     *
     * @param  numeric-string  $quantity  POSITIVE magnitude to destroy
     * @param  string  $returnReceiptId  UUID of the RETURN receipt (S0 linkage target)
     * @param  string  $returnReceiptNumber  Human-readable return receipt number (GL narrative)
     * @param  string  $currencyCode  Explicit currency — never resolved from CompanyContext
     * @param  CarbonInterface|null  $occurredAt  Device event time on the projection path; null = now()
     *
     * @throws ScrapWriteOffUnresolvableException when the product cannot be resolved/valued
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
        ?CarbonInterface $entryDate = null,
        ?string $unitCost = null,
        bool $isHistorical = false,
    ): StockMovement {
        // The movement and its journal entry are ONE unit of work: a costed
        // destruction whose GL leg failed is the very defect V10 exists to fix.
        // (Both callers are already inside a transaction, so this is a savepoint;
        // it also makes the service safe for any future caller that is not.)
        return DB::transaction(function () use (
            $tenantId, $companyId, $locationId, $productId, $quantity,
            $returnReceiptId, $returnReceiptNumber, $currencyCode, $cashierId,
            $variantId, $occurredAt, $entryDate, $unitCost, $isHistorical,
        ): StockMovement {
            // SoftDeletes on Product means an archived product is unresolvable.
            // THROW — never return null (gate C1): the caller's re-entry leg has
            // already applied, and declining quietly would commit a phantom
            // restock of destroyed goods.
            $product = Product::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->find($productId);

            if ($product === null) {
                throw ScrapWriteOffUnresolvableException::forProduct(
                    $productId,
                    $returnReceiptId,
                    'the product is not resolvable in this tenant+company (archived or moved)',
                );
            }

            // The perpetual WAC at rest (`products.cost_price`, 6 dp), read through
            // the ONE shared definition so this flavour and BatchWriteOffService can
            // never post different amounts for the same product (gate I5).
            // Snapshotting it onto the movement is what lets a later correction
            // recover the ORIGINAL cost instead of recomputing from a since-changed
            // average.
            /** @var numeric-string $resolvedUnitCost */
            $resolvedUnitCost = $unitCost ?? $product->resolveMovementUnitCost();

            if (bccomp($resolvedUnitCost, '0', 6) <= 0) { // precision-ok: stock_movements.unit_cost is fixed COST_SCALE=6
                // Not fatal — the destruction still has to be RECORDED — but a
                // zero-valued write-off posts no journal entry, so it must never
                // look like a successful value relief (gate inv-M3).
                Log::warning('ReturnScrapWriteOffService: resolved unit cost is non-positive; the scrap movement will carry no value and NO journal entry will be posted', [
                    'product_id' => $productId,
                    'return_receipt_id' => $returnReceiptId,
                    'unit_cost' => $resolvedUnitCost,
                ]);
            }

            $movement = $this->stockAdjustmentService->issue(
                productId: $productId,
                locationId: $locationId,
                quantity: $quantity,
                reference: "POS return scrap: {$returnReceiptNumber}",
                userId: $cashierId,
                expectedCompanyId: $companyId,
                variantId: $variantId,
                reason: MovementReason::WriteOff,
                unitCost: $resolvedUnitCost,
                referenceType: StockMovementReferenceType::PosReceiptReturnScrap,
                referenceId: $returnReceiptId,
                occurredAt: $occurredAt,
            );

            if ((bool) $movement->is_historical !== $isHistorical) {
                $movement->is_historical = $isHistorical;
                $movement->save();
            }

            $movementOccurredAt = $movement->occurred_at ?? $movement->created_at ?? now();
            $this->glBuffer->enqueue(new MovementGlContext(
                kind: MovementGlKind::BatchWriteOff,
                movementId: $movement->id,
                companyId: $movement->company_id,
                currencyCode: $currencyCode,
                reason: MovementReason::WriteOff,
                quantityBefore: (string) $movement->quantity_before,
                quantityAfter: (string) $movement->quantity_after,
                unitCost: (string) ($movement->unit_cost ?? '0'),
                sourceType: $movement->reference_type,
                sourceId: $movement->reference_id,
                occurredAt: \DateTimeImmutable::createFromInterface($movementOccurredAt),
                entryDate: \DateTimeImmutable::createFromInterface($entryDate ?? $occurredAt ?? now()),
                postedByUserId: $cashierId,
                isHistorical: (bool) $movement->is_historical,
                // The free-text batch label, source coordinate and all posting
                // arguments are byte-identical to V10; only the timing moved.
                // POS scrap remains non-reversible through ReverseWriteOffService.
                batchNumber: "POS-SCRAP {$returnReceiptNumber}",
                productId: $productId,
            ));

            // Post-Wave-3 three-entry arithmetic: sale exit relieves Inventory,
            // return entry restores it, and this write-off relieves it again.
            // The T16 net assertion pins the final Dr Shrinkage / Cr Inventory once.

            return $movement;
        });
    }
}
