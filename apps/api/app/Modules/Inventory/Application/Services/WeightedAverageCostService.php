<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockForFulfilmentException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WeightedAverageCostService - Manages weighted average cost calculations for inventory
 *
 * IMPORTANT: All methods use database transactions with pessimistic locking
 * to prevent race conditions that could corrupt stock quantities and cost prices.
 */
class WeightedAverageCostService
{
    /**
     * Internal precision at which the perpetual WAC unit cost / avg cost / total
     * cost are PERSISTED at rest.
     *
     * Rationale (NC 01 §62 + perpetual-WAC bias): truncating the stored cost to
     * the currency scale at each write biases the running average DOWNWARD and
     * compounds across recomputes. We carry 6 dp (Dynamics-style headroom) at rest
     * and round HALF-UP to the currency scale only at the GL/COGS posting (and
     * display) boundary — never "dans l'enregistrement des opérations".
     */
    private const COST_SCALE = 6;

    public function __construct(
        private readonly MarginService $marginService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ProductCostLock $costLock,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * The precision at which costs are stored at rest.
     */
    private function costScale(): int
    {
        return self::COST_SCALE;
    }

    /**
     * Intermediate working precision for WAC blending.
     *
     * WAC multiplies quantities (scale 4) by unit costs and divides running
     * values. We carry enough digits to (a) keep 4 digits of headroom beyond the
     * currency scale and (b) never truncate below the at-rest COST_SCALE before
     * the division result is persisted. For TND (scale 3) this is 7; for a 0-dp
     * currency it is still >= COST_SCALE + 1.
     */
    private function workingScale(): int
    {
        return max($this->scale() + 4, self::COST_SCALE + 1);
    }

    /**
     * Company-OWNED quantity for a product, as a numeric-string at workingScale.
     *
     * "Owned" = on-hand (sum of every stock_level row for the product within the
     * company) PLUS in-transit (units that have left a source location but have
     * not yet been received, so they live in NO stock_level row). This is the
     * canonical WAC blend denominator basis shared by recordPurchase,
     * recordReturn and recordCostAdjustment so all three agree even during an
     * in-transit window.
     *
     * Concurrency (canonical lock order): this row-locks each stock_level row
     * (real FOR UPDATE on the rows, then sums in PHP — an aggregate
     * sum()->lockForUpdate() locks NO rows on PostgreSQL). It MUST be called
     * INSIDE the per-product advisory lock and BEFORE the product row is locked,
     * so the order stays advisory -> stock_level rows -> product row LAST.
     *
     * @return numeric-string
     */
    private function companyOwnedQuantity(string $productId, string $tenantId, string $companyId): string
    {
        $working = $this->workingScale();

        // Row-lock the ACTUAL stock_level rows, then sum in PHP.
        $levels = StockLevel::query()
            ->where('product_id', $productId)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->get();

        $onHand = '0';
        foreach ($levels as $level) {
            $onHand = bcadd($onHand, (string) $level->quantity, $working);
        }

        // Stock that has left the source but not yet been received still belongs
        // to the company, so it shares in the WAC denominator.
        $inTransit = (string) StockTransferLine::query()
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
            ->where('stock_transfer_lines.product_id', $productId)
            ->where('stock_transfers.tenant_id', $tenantId)
            ->where('stock_transfers.company_id', $companyId)
            ->whereIn('stock_transfers.status', StockTransfer::CARRYING_STATUSES)
            ->toBase()->selectRaw('COALESCE(SUM('.StockTransferLine::REMAINDER_SQL.'), 0) AS remainder')->value('remainder');
        if (! is_numeric($inTransit)) {
            throw new \LogicException('In-transit quantity must be a decimal.');
        }

        return bcadd($onHand, $inTransit, $working);
    }

    /**
     * Record a purchase and update weighted average cost
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * purchase receipts happen concurrently for the same product.
     *
     * Precision contract P0-1: $quantity and $landedUnitCost are numeric-strings.
     * No float rebase. CurrencyScale::bcformat normalises them to the canonical
     * scales (qty→4, cost→workingScale) before any arithmetic.
     *
     * @param  Product  $product  The product being purchased
     * @param  Location  $location  The location receiving the stock
     * @param  numeric-string  $quantity  Quantity being purchased
     * @param  numeric-string  $landedUnitCost  Unit cost including landed costs
     * @param  string|null  $reference  Human-readable reference (e.g., "PO-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     * @param  string|null  $variantId  Variant UUID when the line is for a specific variant; null = product-level stock
     */
    public function recordPurchase(
        Product $product,
        Location $location,
        string $quantity,
        string $landedUnitCost,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $variantId = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $landedUnitCost, $reference, $referenceType, $referenceId, $variantId): StockMovement {
            return $this->costLock->acquire($product->tenant_id, $product->company_id, [$product->id], function () use ($product, $location, $quantity, $landedUnitCost, $reference, $referenceType, $referenceId, $variantId): StockMovement {
                // Lock the variant-scoped physical stock_level row first to prevent
                // concurrent modifications. company_id is in the tuple
                // (api.inventory.032) so the lock cannot be satisfied by a row from
                // another company even if product_id + location_id collide
                // cross-company. variant_id (Task 20) scopes the lock to the
                // correct row: a variant line must not land on the product-level
                // row (variant_id IS NULL).
                $stockLevel = StockLevel::where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->when(
                        $variantId !== null,
                        fn ($q) => $q->where('variant_id', $variantId),
                        fn ($q) => $q->whereNull('variant_id'),
                    )
                    ->lockForUpdate()
                    ->first();

                if ($stockLevel === null) {
                    $stockLevel = StockLevel::create([
                        'id' => Str::uuid()->toString(),
                        'product_id' => $product->id,
                        'variant_id' => $variantId,
                        'location_id' => $location->id,
                        'tenant_id' => $product->tenant_id,
                        'company_id' => $location->company_id,
                        'quantity' => '0',
                        'reserved' => '0',
                    ]);
                }

                $working = $this->workingScale();

                // WAC is product-grain (LOCKED spec §6.7 Option C): variants share
                // one company-wide product cost, so the blend denominator must NOT
                // be scoped by variant_id (that would shard the average per variant
                // — the Task 20 regression). The canonical denominator is dev's
                // shared companyOwnedQuantity() helper: it row-locks EVERY
                // stock_level row for the product (across all variant rows AND all
                // locations) and sums them, then adds in-transit (units that left a
                // source but aren't yet received, in no stock_level row). Using the
                // single shared helper — instead of an inline location-scoped sum —
                // keeps recordPurchase/recordReturn/recordCostAdjustment agreeing on
                // ONE denominator even during an in-transit window, and the FOR
                // UPDATE row-locks (vs an unlocked ->sum()) stop a concurrent
                // lock-free sale on a sibling variant/location row making the
                // denominator stale. Canonical order holds: advisory (already held)
                // -> stock_level rows (inside the helper) -> product row (below).
                $companyQty = CurrencyScale::bcformat(
                    $this->companyOwnedQuantity($product->id, $product->tenant_id, $product->company_id),
                    4
                );

                // Lock product for cost update LAST (canonical order) — scope by
                // the input product's own tenant + company so the lock cannot
                // escalate to a foreign product (defense-in-depth on the
                // upstream-trusted instance).
                $product = Product::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                // Normalise every operand into a numeric string before any
                // arithmetic; quantities carry scale 4, monetary values the
                // working precision. No native float math touches WAC.
                $quantityStr = CurrencyScale::bcformat($quantity, 4);
                $landedUnitCostStr = CurrencyScale::bcformat($landedUnitCost, $working);
                $currentCostPrice = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
                // WAC blend basis is the COMPANY-WIDE owned value (on-hand across
                // every variant row + all locations + in-transit), not the single
                // receiving variant row's value. The receiving variant row's own
                // quantity is tracked separately below as $stockLevelQtyBefore for
                // the physical row update + movement before/after.
                $currentValue = bcmul($companyQty, $currentCostPrice, $working);

                // Company-wide qty/value AFTER the incoming quantity — the blend
                // denominator and numerator.
                // precision-ok: quantities carry the canonical 4-dp quantity scale.
                $newCompanyQty = bcadd($companyQty, $quantityStr, 4);
                $newValue = bcadd($currentValue, bcmul($quantityStr, $landedUnitCostStr, $working), $working);

                // Persist the blended WAC at the higher internal COST_SCALE — NO
                // truncation to the currency scale here. Rounding to the currency
                // scale happens only at the GL/COGS posting (and display) boundary,
                // so the running average no longer compounds a downward bias.
                // Blend against the COMPANY-WIDE quantity (denominator), not the
                // single receiving location's quantity.
                $costScale = $this->costScale();
                $newAvgCost = bccomp($newCompanyQty, '0', 4) > 0
                    ? CurrencyScale::bcformat(bcdiv($newValue, $newCompanyQty, $working), $costScale)
                    : CurrencyScale::bcformat('0', $costScale);

                // The variant row's OWN running quantity drives the physical stock
                // update below and the movement before/after; only the WAC formula
                // uses the product-grain company-owned total above.
                $stockLevelQtyBefore = CurrencyScale::bcformat($stockLevel->quantity, 4);
                $stockLevelQtyAfter = bcadd($stockLevelQtyBefore, $quantityStr, 4); // precision-ok: quantity is decimal(15,4), canonical scale 4

                // Record movement (cost ledger stored at the internal COST_SCALE;
                // variant_id threaded from the receipt line — Task 20).
                $movement = StockMovement::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'location_id' => $location->id,
                    'company_id' => $location->company_id,
                    'movement_type' => MovementType::Receipt,
                    'quantity' => $quantityStr,
                    // before/after reflect the variant-scoped physical row, not the
                    // product-grain WAC aggregate.
                    'quantity_before' => $stockLevelQtyBefore,
                    'quantity_after' => $stockLevelQtyAfter,
                    'unit_cost' => CurrencyScale::bcformat($landedUnitCostStr, $costScale),
                    'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $landedUnitCostStr, $working), $costScale),
                    'avg_cost_before' => CurrencyScale::bcformat($currentCostPrice, $costScale),
                    'avg_cost_after' => $newAvgCost,
                    'reference' => $reference,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'occurred_at' => now(),
                ]);

                // Update the variant-scoped physical stock_level row.
                $stockLevel->quantity = $stockLevelQtyAfter;
                $stockLevel->save();

                // Update product cost at the internal COST_SCALE (no boundary truncation).
                $product->cost_price = $newAvgCost;
                if (bccomp($landedUnitCostStr, '0', $costScale) > 0) {
                    $product->last_purchase_cost = CurrencyScale::bcformat($landedUnitCostStr, $costScale);
                }
                $product->cost_updated_at = now();
                $product->save();

                // Auto-update sale price based on new weighted average cost
                $oldSalePrice = (string) ($product->sale_price ?? '0.00');
                $priceUpdated = $this->marginService->updateSalePrice($product);

                // Capture data for events BEFORE afterCommit
                $movementSnapshot = $movement;
                /** @var Product $productSnapshot */
                $productSnapshot = $product->fresh();
                $locationSnapshot = $location;
                $newStockLevelSnapshot = $stockLevel->quantity;
                $priceUpdatedSnapshot = $priceUpdated;
                // Audit-event cost figures reflect the at-rest COST_SCALE value.
                $oldCostPriceSnapshot = CurrencyScale::bcformat($currentCostPrice, $costScale);
                $newAvgCostSnapshot = $newAvgCost;
                $oldSalePriceSnapshot = $oldSalePrice;
                $referenceSnapshot = $reference;

                // Emit audit event if prices changed - AFTER transaction commits
                if ($priceUpdatedSnapshot) {
                    DB::afterCommit(function () use (
                        $productSnapshot,
                        $oldCostPriceSnapshot,
                        $newAvgCostSnapshot,
                        $oldSalePriceSnapshot,
                        $referenceSnapshot
                    ): void {
                        event(new ProductCostPriceUpdated(
                            productId: $productSnapshot->id,
                            tenantId: $productSnapshot->tenant_id,
                            companyId: $productSnapshot->company_id,
                            productSku: $productSnapshot->sku,
                            oldCostPrice: $oldCostPriceSnapshot,
                            newCostPrice: $newAvgCostSnapshot,
                            oldSalePrice: $oldSalePriceSnapshot,
                            newSalePrice: (string) $productSnapshot->sale_price,
                            reason: 'purchase_receipt',
                            referenceDocument: $referenceSnapshot,
                        ));
                    });
                }

                // Dispatch StockMovementRecorded event for audit trail - AFTER transaction commits
                DB::afterCommit(function () use (
                    $movementSnapshot,
                    $productSnapshot,
                    $locationSnapshot,
                    $newStockLevelSnapshot
                ): void {
                    $this->dispatchStockMovementEvent(
                        movement: $movementSnapshot,
                        product: $productSnapshot,
                        location: $locationSnapshot,
                        movementType: 'purchase',
                        newStockLevel: $newStockLevelSnapshot
                    );
                });

                return $movement;
            });
        }, attempts: 3);
    }

    /**
     * Record a sale (cost comes out at current average)
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * sales happen concurrently for the same product.
     *
     * The movement is classified `MovementReason::Delivery` (DPA Wave 3 T2). The
     * exit seam keys on `MovementReason::affectsCOGS()`, so an unclassified exit
     * is unreachable by it. The single caller is
     * `DeliveryNoteService::issueStock()`; a second caller with a different
     * economic meaning must take a reason parameter rather than inherit this one.
     *
     * @param  Product  $product  The product being sold
     * @param  Location  $location  The location issuing the stock
     * @param  numeric-string  $quantity  Quantity being sold, at the canonical
     *                                    quantity scale. NEVER a float: the
     *                                    column is decimal(15,4) and a float
     *                                    carries ~15-16 significant digits, so a
     *                                    cast loses the 4th decimal on ordinary
     *                                    magnitudes (house rule 19).
     * @param  string|null  $reference  Human-readable reference (e.g., "DN-2025-001")
     * @param  StockMovementReferenceType|null  $referenceType  Morph type of the source document
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordSale(
        Product $product,
        Location $location,
        string $quantity,
        ?string $reference = null,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $reference, $referenceType, $referenceId): StockMovement {
            // Lock stock level to prevent concurrent modifications.
            // company_id added to the tuple (api.inventory.032).
            //
            // 🚨 Campaign N-2: `firstOrFail()` STAYS — this method must never
            // create a `stock_levels` row (pinned by
            // `InventoryCostLockCoverageTest::test_lock_free_methods_operate_only_on_existing_rows`:
            // being lock-free is only safe because it mutates an already-existing
            // row and cannot produce the phantom rows a pure row-lock strategy
            // would miss). What changes is only how the absence is REPORTED: a
            // day-one product has no row, and letting `ModelNotFoundException`
            // escape produced a raw 404 on the two invoice convenience endpoints
            // (and on sales-order confirm, via the reservation lane), while on
            // delivery-note confirm the controller's pre-existing
            // `catch (\RuntimeException)` arm swallowed it into a misleading 422
            // `CONFIGURATION_ERROR` — `ModelNotFoundException` IS a
            // `\RuntimeException` (gate r1 M-1). An absent row is available 0,
            // which is exactly the negative-residual refusal below.
            try {
                $stockLevel = StockLevel::where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } catch (ModelNotFoundException) {
                throw new InsufficientStockForFulfilmentException(
                    productId: $product->id,
                    productName: $product->name,
                    locationId: $location->id,
                    locationName: $location->name,
                    available: '0.0000',
                    requested: QuantityScale::formatForUnit($quantity, null),
                    quantityDecimals: $product->unitOfMeasure?->decimal_places,
                    roundingMethod: $product->unitOfMeasure?->rounding_method->value,
                );
            }

            // Lock product to get consistent cost price — scoped to the
            // input product's own tenant + company.
            $product = Product::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->findOrFail($product->id);

            $working = $this->workingScale();

            $costScale = $this->costScale();
            $quantityStr = CurrencyScale::bcformat($quantity, 4);
            $costPriceStr = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
            // The cost ledger stores the WAC at the internal COST_SCALE (no
            // boundary truncation); COGS rounds to the currency scale downstream.
            $costPriceAtRest = CurrencyScale::bcformat($costPriceStr, $costScale);
            $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
            $newQty = bcsub($currentQty, $quantityStr, 4);

            // Validate sufficient stock.
            //
            // Campaign N-2: this refusal and the absent-row refusal above are the
            // SAME business answer, so they now raise the SAME typed exception and
            // reach the operator as one `INSUFFICIENT_STOCK` 422. It used to be a
            // bare `\DomainException`, which the delivery-note controller
            // mistranslated into `INVALID_STATUS_TRANSITION` — a stock shortfall
            // is not a status-transition error.
            if (bccomp($newQty, '0', 4) < 0) {
                throw new InsufficientStockForFulfilmentException(
                    productId: $product->id,
                    productName: $product->name,
                    locationId: $location->id,
                    locationName: $location->name,
                    available: $currentQty,
                    requested: $quantityStr,
                    quantityDecimals: $product->unitOfMeasure?->decimal_places,
                    roundingMethod: $product->unitOfMeasure?->rounding_method->value,
                );
            }

            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Issue,
                'reason' => MovementReason::Delivery,
                'quantity' => bcmul($quantityStr, '-1', 4),
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => $costPriceAtRest,
                'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $costPriceStr, $working), $costScale),
                'avg_cost_before' => $costPriceAtRest,
                'avg_cost_after' => $costPriceAtRest, // WAC doesn't change on sale
                'reference' => $reference,
                'reference_type' => $referenceType?->value,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
            ]);

            // Update stock level (cost stays same)
            $stockLevel->quantity = $newQty;
            $stockLevel->save();

            // Capture data for events BEFORE afterCommit
            $movementSnapshot = $movement;
            $productSnapshot = $product;
            $locationSnapshot = $location;
            $newStockLevelSnapshot = $stockLevel->quantity;

            // Dispatch StockMovementRecorded event for audit trail - AFTER transaction commits
            DB::afterCommit(function () use (
                $movementSnapshot,
                $productSnapshot,
                $locationSnapshot,
                $newStockLevelSnapshot
            ): void {
                $this->dispatchStockMovementEvent(
                    movement: $movementSnapshot,
                    product: $productSnapshot,
                    location: $locationSnapshot,
                    movementType: 'sale',
                    newStockLevel: $newStockLevelSnapshot
                );
            });

            return $movement;
        });
    }

    /**
     * Record a return (stock comes back at original cost)
     *
     * Uses pessimistic locking to prevent race conditions.
     *
     * The movement is classified `MovementReason::CustomerReturn` (DPA Wave 3 T2)
     * — the sales-return counterpart of `recordSale`'s `Delivery`, and likewise
     * COGS-bearing. The single caller is `ReturnNoteService::receiveStockBack()`.
     *
     * @param  Product  $product  The product being returned
     * @param  Location  $location  The location receiving the return
     * @param  numeric-string  $quantity  Quantity being returned (see recordSale)
     * @param  numeric-string  $originalCost  Original unit cost of the returned
     *                                        items. NEVER a float: the column is
     *                                        decimal(19,6) (house rule 19).
     * @param  string|null  $reference  Human-readable reference (e.g., "RN-2025-001")
     * @param  StockMovementReferenceType|null  $referenceType  Morph type of the source document
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordReturn(
        Product $product,
        Location $location,
        string $quantity,
        string $originalCost,
        ?string $reference = null,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $originalCost, $reference, $referenceType, $referenceId): StockMovement {
            return $this->costLock->acquire($product->tenant_id, $product->company_id, [$product->id], function () use ($product, $location, $quantity, $originalCost, $reference, $referenceType, $referenceId): StockMovement {
                // Lock stock level first. company_id added to the tuple (api.inventory.032).
                $stockLevel = StockLevel::where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->lockForUpdate()
                    ->first();

                if ($stockLevel === null) {
                    $stockLevel = StockLevel::create([
                        'id' => Str::uuid()->toString(),
                        'product_id' => $product->id,
                        'location_id' => $location->id,
                        'tenant_id' => $product->tenant_id,
                        'company_id' => $location->company_id,
                        'quantity' => '0',
                        'reserved' => '0',
                    ]);
                }

                $working = $this->workingScale();

                // Company-OWNED quantity is the WAC blend basis (mirrors
                // recordPurchase / recordCostAdjustment): cost is company-wide
                // per product, so a return into one location must blend against
                // every owned unit, not just the receiving location's qty.
                // "Owned" = on-hand (every stock_level row) + in-transit, so the
                // basis stays consistent with recordCostAdjustment during an
                // in-transit window. The shared helper row-locks the stock_level
                // rows (canonical order: advisory held -> stock_level rows FOR
                // UPDATE -> product row LAST) BEFORE the product row below.
                $companyQty = CurrencyScale::bcformat(
                    $this->companyOwnedQuantity($product->id, $product->tenant_id, $product->company_id),
                    4
                );

                // Lock product for cost update LAST (canonical order) — scoped to
                // the input product's own tenant + company.
                $product = Product::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                // Normalise operands to numeric strings; quantity at scale 4,
                // monetary values at the working precision. No native float math.
                $quantityStr = CurrencyScale::bcformat($quantity, 4);
                $originalCostStr = CurrencyScale::bcformat($originalCost, $working);
                // Receiving location qty (drives the stock_level row update only).
                $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
                $currentCostPrice = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
                // WAC blend basis is the COMPANY-WIDE on-hand value.
                $currentValue = bcmul($companyQty, $currentCostPrice, $working);

                // Receiving location's new qty (only this row's quantity changes).
                $newQty = bcadd($currentQty, $quantityStr, 4);
                // Company-wide qty/value AFTER the incoming quantity.
                // precision-ok: quantities carry the canonical 4-dp quantity scale.
                $newCompanyQty = bcadd($companyQty, $quantityStr, 4);
                $newValue = bcadd($currentValue, bcmul($quantityStr, $originalCostStr, $working), $working);

                // Persist the blended WAC at the higher internal COST_SCALE — NO
                // boundary truncation (see recordPurchase()). Rounding to the
                // currency scale happens only at the GL/COGS posting boundary.
                // Blend against the COMPANY-WIDE quantity (denominator).
                $costScale = $this->costScale();
                $newAvgCost = bccomp($newCompanyQty, '0', 4) > 0
                    ? CurrencyScale::bcformat(bcdiv($newValue, $newCompanyQty, $working), $costScale)
                    : CurrencyScale::bcformat('0', $costScale);

                // Record movement (cost ledger stored at the internal COST_SCALE).
                // quantity_before/after track the RECEIVING location's row.
                $movement = StockMovement::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'company_id' => $location->company_id,
                    'movement_type' => MovementType::Receipt,
                    'reason' => MovementReason::CustomerReturn,
                    'quantity' => $quantityStr,
                    'quantity_before' => $currentQty,
                    'quantity_after' => $newQty,
                    'unit_cost' => CurrencyScale::bcformat($originalCostStr, $costScale),
                    'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $originalCostStr, $working), $costScale),
                    'avg_cost_before' => CurrencyScale::bcformat($currentCostPrice, $costScale),
                    'avg_cost_after' => $newAvgCost,
                    'reference' => $reference,
                    'reference_type' => $referenceType?->value,
                    'reference_id' => $referenceId,
                    'occurred_at' => now(),
                ]);

                // Update stock level
                $stockLevel->quantity = $newQty;
                $stockLevel->save();

                // Update product cost at the internal COST_SCALE (no boundary truncation).
                $product->cost_price = $newAvgCost;
                $product->cost_updated_at = now();
                $product->save();

                // Auto-update sale price based on new weighted average cost
                $oldSalePrice = (string) ($product->sale_price ?? '0.00');
                $priceUpdated = $this->marginService->updateSalePrice($product);

                // Capture data for events BEFORE afterCommit
                $movementSnapshot = $movement;
                /** @var Product $productSnapshot */
                $productSnapshot = $product->fresh();
                $locationSnapshot = $location;
                $newStockLevelSnapshot = $stockLevel->quantity;
                $priceUpdatedSnapshot = $priceUpdated;
                // Audit-event cost figures reflect the at-rest COST_SCALE value.
                $oldCostPriceSnapshot = CurrencyScale::bcformat($currentCostPrice, $costScale);
                $newAvgCostSnapshot = $newAvgCost;
                $oldSalePriceSnapshot = $oldSalePrice;
                $referenceSnapshot = $reference;

                // Emit audit event if prices changed - AFTER transaction commits
                if ($priceUpdatedSnapshot) {
                    DB::afterCommit(function () use (
                        $productSnapshot,
                        $oldCostPriceSnapshot,
                        $newAvgCostSnapshot,
                        $oldSalePriceSnapshot,
                        $referenceSnapshot
                    ): void {
                        event(new ProductCostPriceUpdated(
                            productId: $productSnapshot->id,
                            tenantId: $productSnapshot->tenant_id,
                            companyId: $productSnapshot->company_id,
                            productSku: $productSnapshot->sku,
                            oldCostPrice: $oldCostPriceSnapshot,
                            newCostPrice: $newAvgCostSnapshot,
                            oldSalePrice: $oldSalePriceSnapshot,
                            newSalePrice: (string) $productSnapshot->sale_price,
                            reason: 'product_return',
                            referenceDocument: $referenceSnapshot,
                        ));
                    });
                }

                // Dispatch StockMovementRecorded event for audit trail - AFTER transaction commits
                DB::afterCommit(function () use (
                    $movementSnapshot,
                    $productSnapshot,
                    $locationSnapshot,
                    $newStockLevelSnapshot
                ): void {
                    $this->dispatchStockMovementEvent(
                        movement: $movementSnapshot,
                        product: $productSnapshot,
                        location: $locationSnapshot,
                        movementType: 'return',
                        newStockLevel: $newStockLevelSnapshot
                    );
                });

                return $movement;
            });
        }, attempts: 3);
    }

    /**
     * Record a cost adjustment that capitalizes additional costs into the
     * company-wide weighted average cost without moving any quantity.
     *
     * Use this for branch-transfer freight/handling, supplier rebates,
     * duty adjustments, write-downs, or any other WAC-affecting event
     * that does not change on-hand quantity.
     *
     * new_avg = current_avg + additional_cost / company_on_hand_qty
     *
     * If on-hand quantity is zero the adjustment is skipped (the WAC is
     * undefined for an empty bucket — the caller should surface this).
     *
     * Concurrency (WAC Serialization Foundation):
     *  - takes a per-product TRANSACTION-scoped advisory lock so the cost_price
     *    write is serialized against every other recompute for this product;
     *  - row-locks each stock_level row (real FOR UPDATE on the rows, then sums
     *    in PHP — an aggregate sum()->lockForUpdate() locks NO rows on Postgres);
     *  - includes in-transit transfer quantity in the denominator so the company
     *    still "owns" stock that has left the source but not yet been received.
     *
     * @param  Product  $product  The product whose WAC is being adjusted
     * @param  numeric-string  $additionalCost  Cost amount to capitalize (positive = increases WAC)
     * @param  string  $reason  Free-text reason for audit trail (e.g. "transfer freight")
     * @param  string  $tenantId  Owning tenant (resolved from the product, never a location)
     * @param  string  $companyId  Owning company (WAC is company-wide)
     * @param  string|null  $reference  Human-readable reference
     * @param  string|null  $referenceType  Source model class for audit linking
     * @param  string|null  $referenceId  Source model UUID for audit linking
     */
    public function recordCostAdjustment(
        Product $product,
        string $additionalCost,
        string $reason,
        string $tenantId,
        string $companyId,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($product, $additionalCost, $reason, $tenantId, $companyId, $reference, $referenceType, $referenceId): ?StockMovement {
            return $this->costLock->acquire($tenantId, $companyId, [$product->id], function () use ($product, $additionalCost, $reason, $tenantId, $companyId, $reference, $referenceType, $referenceId): ?StockMovement {
                // Canonical lock order: advisory (already held) -> stock_level
                // rows -> product row LAST. recordSale() (outside the advisory
                // seam) locks stock_level then product; taking the product row
                // first here would invert that order and deadlock a concurrent
                // sale on the same product (AB-BA).
                //
                // Company-OWNED quantity = on-hand (every stock_level row,
                // row-locked FOR UPDATE then summed in PHP) + in-transit (units
                // that left a source but aren't yet received). The shared helper
                // performs the stock_level row locking BEFORE the product row is
                // locked below, preserving the canonical order. Stock that has
                // left the source but not yet been received still belongs to the
                // company, so it shares in the capitalized cost.
                $working = $this->workingScale();
                $totalOwned = $this->companyOwnedQuantity($product->id, $tenantId, $companyId);

                if (bccomp($totalOwned, '0', $working) <= 0) {
                    // Nothing owned to capitalize against — no-op, no movement.
                    return null;
                }

                // Lock the product row LAST (canonical order: advisory ->
                // stock_level rows -> product row), just before the cost write,
                // scoped to the input product's own tenant + company.
                $product = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                // Capitalize the additional cost across every owned unit using
                // bcmath only — mirror recordPurchase: carry the persisted cost at
                // the internal COST_SCALE with NO truncation to the currency scale.
                $costScale = $this->costScale();
                $currentCostStr = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
                $additionalCostStr = CurrencyScale::bcformatStrict($additionalCost, $working);
                $delta = bcdiv($additionalCostStr, $totalOwned, $working);
                $newAvgCost = CurrencyScale::bcformat(bcadd($currentCostStr, $delta, $working), $costScale);

                // Anchor the adjustment movement at the product's "home" location
                // (any stock_level row will do — we pick the one with the largest qty
                // so the audit trail naturally points at where the cost lives).
                /** @var StockLevel|null $anchor */
                $anchor = StockLevel::query()
                    ->where('product_id', $product->id)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->orderByDesc('quantity')
                    ->first();

                if ($anchor === null) {
                    return null;
                }

                // quantity = 0 movement — the qty isn't changing, only the average cost.
                $movement = StockMovement::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'location_id' => $anchor->location_id,
                    'company_id' => $product->company_id,
                    'movement_type' => MovementType::Adjustment,
                    'quantity' => '0',
                    'quantity_before' => $totalOwned,
                    'quantity_after' => $totalOwned,
                    'unit_cost' => CurrencyScale::bcformat($additionalCostStr, $costScale),
                    'total_cost' => CurrencyScale::bcformat($additionalCostStr, $costScale),
                    'avg_cost_before' => CurrencyScale::bcformat($currentCostStr, $costScale),
                    'avg_cost_after' => $newAvgCost,
                    'reference' => $reference,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'notes' => $reason,
                    'occurred_at' => now(),
                ]);

                $product->cost_price = $newAvgCost;
                $product->cost_updated_at = now();
                $product->save();

                $oldSalePrice = (string) ($product->sale_price ?? '0.00');
                $priceUpdated = $this->marginService->updateSalePrice($product);

                $productSnapshot = $product->fresh() ?? $product;
                $oldCostPriceSnapshot = CurrencyScale::bcformat($currentCostStr, $costScale);
                $newAvgCostSnapshot = $newAvgCost;
                $oldSalePriceSnapshot = $oldSalePrice;
                $referenceSnapshot = $reference;
                $reasonSnapshot = $reason;

                if ($priceUpdated) {
                    DB::afterCommit(function () use (
                        $productSnapshot,
                        $oldCostPriceSnapshot,
                        $newAvgCostSnapshot,
                        $oldSalePriceSnapshot,
                        $referenceSnapshot,
                        $reasonSnapshot,
                    ): void {
                        event(new ProductCostPriceUpdated(
                            productId: $productSnapshot->id,
                            tenantId: $productSnapshot->tenant_id,
                            companyId: $productSnapshot->company_id,
                            productSku: $productSnapshot->sku,
                            oldCostPrice: $oldCostPriceSnapshot,
                            newCostPrice: $newAvgCostSnapshot,
                            oldSalePrice: $oldSalePriceSnapshot,
                            newSalePrice: (string) $productSnapshot->sale_price,
                            reason: $reasonSnapshot,
                            referenceDocument: $referenceSnapshot,
                        ));
                    });
                }

                return $movement;
            });
        }, attempts: 3);
    }

    /**
     * Calculate what the new weighted average cost would be without recording.
     *
     * Precision contract P0-1: all four parameters and the return value are
     * numeric-strings. No float touches WAC arithmetic.
     *
     * Scales used:
     *   $cs = COST_SCALE = 6  (internal at-rest precision; constant, resolver-safe)
     *   $qs = 4               (canonical quantity scale; hardcoded throughout)
     * Intermediates carry $cs+1 = 7 digits to avoid losing the last persisted
     * digit before the final bcformat truncation to $cs.
     *
     * @param  numeric-string  $currentQty  On-hand quantity before the receipt
     * @param  numeric-string  $currentCost  Current WAC unit cost at rest (6 dp)
     * @param  numeric-string  $newQty  Incoming receipt quantity
     * @param  numeric-string  $newCost  Incoming receipt unit cost
     * @return numeric-string New WAC, formatted to COST_SCALE (6 dp), truncated
     */
    public function calculateNewWAC(
        string $currentQty,
        string $currentCost,
        string $newQty,
        string $newCost
    ): string {
        $cs = $this->costScale(); // 6 — constant, never resolver-dependent
        $qs = 4;                  // canonical quantity scale

        $totalCost = bcadd(
            bcmul($currentQty, $currentCost, $cs + 1),
            bcmul($newQty, $newCost, $cs + 1),
            $cs + 1
        );
        $totalQty = bcadd($currentQty, $newQty, $qs);

        return bccomp($totalQty, '0', $qs) === 0
            ? CurrencyScale::bcformat('0', $cs)
            : CurrencyScale::bcformat(bcdiv($totalCost, $totalQty, $cs + 1), $cs);
    }

    /**
     * Dispatch StockMovementRecorded event for audit trail.
     *
     * @param  StockMovement  $movement  The stock movement record
     * @param  Product  $product  The product being moved
     * @param  Location  $location  The location of the movement
     * @param  string  $movementType  Type of movement (purchase, sale, return)
     * @param  string  $newStockLevel  The new stock level after movement
     */
    private function dispatchStockMovementEvent(
        StockMovement $movement,
        Product $product,
        Location $location,
        string $movementType,
        string $newStockLevel
    ): void {
        event(new StockMovementRecorded(
            movementId: $movement->id,
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            locationId: $location->id,
            movementType: $movementType,
            quantity: (string) $movement->quantity,
            unitCost: (string) $movement->unit_cost,
            totalCost: (string) $movement->total_cost,
            newStockLevel: $newStockLevel,
            reference: $movement->reference,
            referenceType: $movement->reference_type,
            referenceId: $movement->reference_id,
            occurredAt: now()->toIso8601String(),
        ));

        // V2 dual-dispatch (variant-aware). The variant is read from the
        // persisted movement row (variant_id is written by Task 15/Task 20); it
        // may be null for product-level movements, which is expected.
        event(new StockMovementRecordedV2(
            movementId: $movement->id,
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            locationId: $location->id,
            movementType: $movementType,
            quantity: (string) $movement->quantity,
            unitCost: (string) $movement->unit_cost,
            totalCost: (string) $movement->total_cost,
            newStockLevel: $newStockLevel,
            variantId: $movement->variant_id,
            reference: $movement->reference,
            referenceType: $movement->reference_type,
            referenceId: $movement->reference_id,
            occurredAt: now()->toIso8601String(),
        ));
    }
}
