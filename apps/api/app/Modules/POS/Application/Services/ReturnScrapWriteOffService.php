<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Exceptions\ScrapWriteOffUnresolvableException;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
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
 *   linkage) + `GeneralLedgerService::createInventoryWriteOffEntry()` keyed on the
 *   movement id (Dr COGS / Cr Inventory).
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
    ): StockMovement {
        // The movement and its journal entry are ONE unit of work: a costed
        // destruction whose GL leg failed is the very defect V10 exists to fix.
        // (Both callers are already inside a transaction, so this is a savepoint;
        // it also makes the service safe for any future caller that is not.)
        return DB::transaction(function () use (
            $tenantId, $companyId, $locationId, $productId, $quantity,
            $returnReceiptId, $returnReceiptNumber, $currencyCode, $cashierId,
            $variantId, $occurredAt,
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
            /** @var numeric-string $unitCost */
            $unitCost = $product->resolveMovementUnitCost();

            $scale = $this->scaleResolver->getScale($currencyCode);

            if (bccomp($unitCost, '0', $scale) <= 0) {
                // Not fatal — the destruction still has to be RECORDED — but a
                // zero-valued write-off posts no journal entry, so it must never
                // look like a successful value relief (gate inv-M3).
                Log::warning('ReturnScrapWriteOffService: resolved unit cost is non-positive; the scrap movement will carry no value and NO journal entry will be posted', [
                    'product_id' => $productId,
                    'return_receipt_id' => $returnReceiptId,
                    'unit_cost' => $unitCost,
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
                unitCost: $unitCost,
                referenceType: StockMovementReferenceType::PosReceiptReturnScrap,
                referenceId: $returnReceiptId,
                occurredAt: $occurredAt,
            );

            // Round HALF-UP ONCE at the GL posting boundary (rule 19). bcmul alone
            // truncates, which biases every scrap toward UNDER-relieving Inventory
            // (qty 3 x 1.6666 -> 4.999 instead of 5.000); the movement's own
            // total_cost keeps the full COST_SCALE=6 precision. Same discipline as
            // createCOGSEntry, which computes at working precision then bcrounds.
            $working = $scale + 6;
            /** @var numeric-string $amount */
            $amount = CurrencyScale::bcround(bcmul($quantity, $unitCost, $working), $scale);

            // Only the "chart of accounts not configured for this company" case is
            // tolerated — an accounting-setup gap must not refuse a customer's
            // refund. It is checked UP FRONT rather than caught, because
            // `Account::findByPurposeOrFail` throws a bare RuntimeException and a
            // catch would also swallow ModelNotFound / QueryException /
            // ClosedFiscalPeriod — exactly the silent no-GL hole gates M4 + I7
            // flagged. Everything else propagates and rolls the pair back.
            if (! $this->glService->hasInventoryWriteOffAccounts($companyId)) {
                Log::warning('ReturnScrapWriteOffService: company has no COGS/Inventory system accounts; the scrap movement is recorded WITHOUT a journal entry (inventory value stays on the balance sheet until the chart of accounts is configured)', [
                    'company_id' => $companyId,
                    'return_receipt_id' => $returnReceiptId,
                    'movement_id' => $movement->id,
                    'amount' => $amount,
                ]);

                return $movement;
            }

            $this->glService->createInventoryWriteOffEntry(
                companyId: $companyId,
                // The `batchNumber` parameter is the free-text source label the GL
                // narrative interpolates; a POS scrap has no lot, so it carries the
                // return receipt number instead. `source_type` stays
                // 'batch_write_off' deliberately — that is the canonical inventory
                // write-off coordinate the reversal machinery looks up.
                //
                // NOTE (gate C3): a POS scrap is NOT reversible through
                // ReverseWriteOffService — it is undone by correcting the return.
                // That service refuses this reference_type explicitly.
                batchNumber: "POS-SCRAP {$returnReceiptNumber}",
                productId: $productId,
                amount: $amount,
                reason: MovementReason::WriteOff,
                movementId: $movement->id,
                postedByUserId: $cashierId,
                currencyCode: $currencyCode,
                // Seal INSIDE this transaction (gate I1). The default afterCommit
                // path would post from a Horizon worker in autocommit, where the
                // per-company chain advisory lock is a no-op (duplicate
                // chain_sequence) and a post-commit throw would leave the entry
                // Draft forever — invisible to every trial balance.
                postSynchronously: true,
            );

            // ── GL ORDERING DEPENDENCY (gate inv-I1) ──────────────────────────
            // This posting is economically correct TODAY only because two other
            // postings are absent, and they must land TOGETHER or this breaks:
            //   * POS COGS-at-exit (a POS sale currently posts no COGS) — landing
            //     it alone relieves Inventory TWICE for the same units.
            //   * A GL leg for the RE-ENTRY movement (MovementReason::POSReturn
            //     already declares requiresGLEntry() === true) — landing it alone
            //     nets the pair to zero GL while the subledger dropped qty x WAC.
            // Recorded at program level in the DPA register; do not land either
            // lane without revisiting this site.

            return $movement;
        });
    }
}
