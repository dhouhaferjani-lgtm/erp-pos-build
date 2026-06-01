<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Events\ProductCostPriceUpdated;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
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
     * Record a purchase and update weighted average cost
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * purchase receipts happen concurrently for the same product.
     *
     * @param  Product  $product  The product being purchased
     * @param  Location  $location  The location receiving the stock
     * @param  float  $quantity  Quantity being purchased
     * @param  float  $landedUnitCost  Unit cost including landed costs
     * @param  string|null  $reference  Human-readable reference (e.g., "PO-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordPurchase(
        Product $product,
        Location $location,
        float $quantity,
        float $landedUnitCost,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $landedUnitCost, $reference, $referenceType, $referenceId): StockMovement {
            return $this->costLock->acquire($product->tenant_id, $product->company_id, [$product->id], function () use ($product, $location, $quantity, $landedUnitCost, $reference, $referenceType, $referenceId): StockMovement {
                // Lock stock level first to prevent concurrent modifications.
                // company_id added to the tuple (api.inventory.032) so the lock
                // cannot be satisfied by a StockLevel row from another company
                // even if product_id + location_id happen to collide cross-company.
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

                // Lock product for cost update — scope by the input product's
                // own tenant + company so the lock cannot escalate to a foreign
                // product (defense-in-depth on the upstream-trusted instance).
                $product = Product::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                $working = $this->workingScale();

                // Normalise every operand into a numeric string before any
                // arithmetic; quantities carry scale 4, monetary values the
                // working precision. No native float math touches WAC.
                $quantityStr = CurrencyScale::bcformat($quantity, 4);
                $landedUnitCostStr = CurrencyScale::bcformat($landedUnitCost, $working);
                $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
                $currentCostPrice = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
                $currentValue = bcmul($currentQty, $currentCostPrice, $working);

                $newQty = bcadd($currentQty, $quantityStr, 4);
                $newValue = bcadd($currentValue, bcmul($quantityStr, $landedUnitCostStr, $working), $working);

                // Persist the blended WAC at the higher internal COST_SCALE — NO
                // truncation to the currency scale here. Rounding to the currency
                // scale happens only at the GL/COGS posting (and display) boundary,
                // so the running average no longer compounds a downward bias.
                $costScale = $this->costScale();
                $newAvgCost = bccomp($newQty, '0', 4) > 0
                    ? CurrencyScale::bcformat(bcdiv($newValue, $newQty, $working), $costScale)
                    : CurrencyScale::bcformat('0', $costScale);

                // Record movement (cost ledger stored at the internal COST_SCALE).
                $movement = StockMovement::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'company_id' => $location->company_id,
                    'movement_type' => MovementType::Receipt,
                    'quantity' => $quantityStr,
                    'quantity_before' => $currentQty,
                    'quantity_after' => $newQty,
                    'unit_cost' => CurrencyScale::bcformat($landedUnitCostStr, $costScale),
                    'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $landedUnitCostStr, $working), $costScale),
                    'avg_cost_before' => CurrencyScale::bcformat($currentCostPrice, $costScale),
                    'avg_cost_after' => $newAvgCost,
                    'reference' => $reference,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);

                // Update stock level
                $stockLevel->quantity = $newQty;
                $stockLevel->save();

                // Update product cost at the internal COST_SCALE (no boundary truncation).
                $product->cost_price = $newAvgCost;
                $product->last_purchase_cost = CurrencyScale::bcformat($landedUnitCostStr, $costScale);
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
        });
    }

    /**
     * Record a sale (cost comes out at current average)
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * sales happen concurrently for the same product.
     *
     * @param  Product  $product  The product being sold
     * @param  Location  $location  The location issuing the stock
     * @param  float  $quantity  Quantity being sold
     * @param  string|null  $reference  Human-readable reference (e.g., "DN-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordSale(
        Product $product,
        Location $location,
        float $quantity,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return DB::transaction(function () use ($product, $location, $quantity, $reference, $referenceType, $referenceId): StockMovement {
            // Lock stock level to prevent concurrent modifications.
            // company_id added to the tuple (api.inventory.032).
            $stockLevel = StockLevel::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->where('tenant_id', $product->tenant_id)
                ->where('company_id', $product->company_id)
                ->lockForUpdate()
                ->firstOrFail();

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

            // Validate sufficient stock
            if (bccomp($newQty, '0', 4) < 0) {
                throw new \DomainException(
                    "Insufficient stock for product {$product->id}. Available: {$currentQty}, Requested: {$quantityStr}"
                );
            }

            $movement = StockMovement::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'company_id' => $location->company_id,
                'movement_type' => MovementType::Issue,
                'quantity' => bcmul($quantityStr, '-1', 4),
                'quantity_before' => $currentQty,
                'quantity_after' => $newQty,
                'unit_cost' => $costPriceAtRest,
                'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $costPriceStr, $working), $costScale),
                'avg_cost_before' => $costPriceAtRest,
                'avg_cost_after' => $costPriceAtRest, // WAC doesn't change on sale
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
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
     * @param  Product  $product  The product being returned
     * @param  Location  $location  The location receiving the return
     * @param  float  $quantity  Quantity being returned
     * @param  float  $originalCost  Original cost of the returned items
     * @param  string|null  $reference  Human-readable reference (e.g., "RN-2025-001")
     * @param  string|null  $referenceType  Type of source document (e.g., "Document")
     * @param  string|null  $referenceId  UUID of source document for audit trail
     */
    public function recordReturn(
        Product $product,
        Location $location,
        float $quantity,
        float $originalCost,
        ?string $reference = null,
        ?string $referenceType = null,
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

                // Lock product for cost update — scoped to the input product's
                // own tenant + company.
                $product = Product::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('company_id', $product->company_id)
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                $working = $this->workingScale();

                // Normalise operands to numeric strings; quantity at scale 4,
                // monetary values at the working precision. No native float math.
                $quantityStr = CurrencyScale::bcformat($quantity, 4);
                $originalCostStr = CurrencyScale::bcformat($originalCost, $working);
                $currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4);
                $currentCostPrice = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
                $currentValue = bcmul($currentQty, $currentCostPrice, $working);

                $newQty = bcadd($currentQty, $quantityStr, 4);
                $newValue = bcadd($currentValue, bcmul($quantityStr, $originalCostStr, $working), $working);

                // Persist the blended WAC at the higher internal COST_SCALE — NO
                // boundary truncation (see recordPurchase()). Rounding to the
                // currency scale happens only at the GL/COGS posting boundary.
                $costScale = $this->costScale();
                $newAvgCost = bccomp($newQty, '0', 4) > 0
                    ? CurrencyScale::bcformat(bcdiv($newValue, $newQty, $working), $costScale)
                    : CurrencyScale::bcformat('0', $costScale);

                // Record movement (cost ledger stored at the internal COST_SCALE).
                $movement = StockMovement::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'location_id' => $location->id,
                    'company_id' => $location->company_id,
                    'movement_type' => MovementType::Receipt,
                    'quantity' => $quantityStr,
                    'quantity_before' => $currentQty,
                    'quantity_after' => $newQty,
                    'unit_cost' => CurrencyScale::bcformat($originalCostStr, $costScale),
                    'total_cost' => CurrencyScale::bcformat(bcmul($quantityStr, $originalCostStr, $working), $costScale),
                    'avg_cost_before' => CurrencyScale::bcformat($currentCostPrice, $costScale),
                    'avg_cost_after' => $newAvgCost,
                    'reference' => $reference,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
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
        });
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
     * @param  float  $additionalCost  Cost amount to capitalize (positive = increases WAC)
     * @param  string  $reason  Free-text reason for audit trail (e.g. "transfer freight")
     * @param  string  $tenantId  Owning tenant (resolved from the product, never a location)
     * @param  string  $companyId  Owning company (WAC is company-wide)
     * @param  string|null  $reference  Human-readable reference
     * @param  string|null  $referenceType  Source model class for audit linking
     * @param  string|null  $referenceId  Source model UUID for audit linking
     */
    public function recordCostAdjustment(
        Product $product,
        float $additionalCost,
        string $reason,
        string $tenantId,
        string $companyId,
        ?string $reference = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($product, $additionalCost, $reason, $tenantId, $companyId, $reference, $referenceType, $referenceId): ?StockMovement {
            return $this->costLock->acquire($tenantId, $companyId, [$product->id], function () use ($product, $additionalCost, $reason, $tenantId, $companyId, $reference, $referenceType, $referenceId): ?StockMovement {
                // Lock product first so the cost_price write is serialized.
                $product = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->findOrFail($product->id);

                // Company-wide on-hand quantity. Row-lock the ACTUAL stock_level
                // rows (FOR UPDATE on each row), then sum in PHP — an aggregate
                // sum()->lockForUpdate() locks no rows on PostgreSQL and would let
                // concurrent stock motion race the WAC delta computation.
                $levels = StockLevel::query()
                    ->where('product_id', $product->id)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->get();

                $working = $this->workingScale();
                $onHand = '0';
                foreach ($levels as $level) {
                    $onHand = bcadd($onHand, (string) $level->quantity, $working);
                }

                // Stock that has left the source but not yet been received still
                // belongs to the company, so it shares in the capitalized cost.
                $inTransit = (string) StockTransferLine::query()
                    ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
                    ->where('stock_transfer_lines.product_id', $product->id)
                    ->where('stock_transfers.tenant_id', $tenantId)
                    ->where('stock_transfers.company_id', $companyId)
                    ->where('stock_transfers.status', TransferStatus::InTransit)
                    ->sum('stock_transfer_lines.quantity');

                $totalOwned = bcadd($onHand, $inTransit, $working);

                if (bccomp($totalOwned, '0', $working) <= 0) {
                    // Nothing owned to capitalize against — no-op, no movement.
                    return null;
                }

                // Capitalize the additional cost across every owned unit using
                // bcmath only — mirror recordPurchase: carry the persisted cost at
                // the internal COST_SCALE with NO truncation to the currency scale.
                $costScale = $this->costScale();
                $currentCostStr = CurrencyScale::bcformat($product->cost_price ?? '0', $working);
                $additionalCostStr = CurrencyScale::bcformat($additionalCost, $working);
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
        });
    }

    /**
     * Calculate what the new weighted average cost would be without recording
     */
    public function calculateNewWAC(
        float $currentQty,
        float $currentCost,
        float $newQty,
        float $newCost
    ): float {
        $working = $this->workingScale();

        $currentQtyStr = CurrencyScale::bcformat($currentQty, 4);
        $newQtyStr = CurrencyScale::bcformat($newQty, 4);
        $currentCostStr = CurrencyScale::bcformat($currentCost, $working);
        $newCostStr = CurrencyScale::bcformat($newCost, $working);

        $totalQty = bcadd($currentQtyStr, $newQtyStr, 4);

        if (bccomp($totalQty, '0', 4) <= 0) {
            return 0.0;
        }

        $currentValue = bcmul($currentQtyStr, $currentCostStr, $working);
        $newValue = bcmul($newQtyStr, $newCostStr, $working);
        $blended = bcdiv(bcadd($currentValue, $newValue, $working), $totalQty, $working);

        // Carry to the internal COST_SCALE (no currency-scale truncation), then
        // surface as float to keep this preview helper's published return type.
        // This mirrors what recordPurchase()/recordReturn() now persist.
        return (float) CurrencyScale::bcformat($blended, $this->costScale());
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
    }
}
