<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationCreatedV2;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\Inventory\Domain\Events\ReservationReleasedV2;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\Exceptions\VariantRequiredException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockAdjustmentService
{
    /**
     * Quantity precision (decimal places) for all stock math.
     *
     * Must match the `decimal(15,4)` storage scale on `stock_levels` and
     * `stock_movements` and the `decimal:4` casts on their models. Inventory
     * quantities are produced at 4 decimal places (transfer/document/counting
     * lines); operating bcmath at a lower scale here silently truncates them
     * and leaves un-reconcilable ghost stock.
     */
    private const SCALE = 4;

    /**
     * Precision at which cost columns (unit_cost, total_cost) are persisted at rest.
     *
     * Mirrors WeightedAverageCostService::COST_SCALE. Costs are stored at 6 decimal
     * places to prevent compounding downward bias when the WAC formula repeatedly
     * truncates to the currency scale; rounding to the currency scale happens only
     * at the GL/COGS posting (and display) boundary.
     */
    private const COST_SCALE = 6;

    public function __construct(
        private readonly ProductVariantLookup $variantLookup,
        private readonly ProductCostLock $costLock,
        private readonly BatchStockService $batchStockService,
    ) {}

    /**
     * Receive stock into a location (e.g., from purchase order).
     *
     * @param  numeric-string  $quantity
     * @param  numeric-string|null  $unitCost  Per-unit cost at COST_SCALE=6; when provided,
     *                                         unit_cost and total_cost are persisted on the
     *                                         movement row so reversals can recover the original
     *                                         cost without recomputing from a changed WAC.
     * @param  int|null  $batchId  Optional batch ID for batch-tracked products
     */
    public function receive(
        string $productId,
        string $locationId,
        string $quantity,
        string $reference,
        string $userId,
        ?int $batchId = null,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
        ?MovementReason $reason = null,
        ?string $unitCost = null,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId, $expectedCompanyId, $variantId, $reason, $unitCost): StockMovement {
            $companyId = $expectedCompanyId ?? $this->resolveCompanyId($locationId);

            // WAC serialization seam: take the per-product advisory lock FIRST
            // (canonical order advisory -> stock_level -> product). The advisory
            // key is product-grain ([$productId]) even when the row we touch is
            // variant-scoped — variant cost is advisory only; WAC stays
            // product-grain (§6.7).
            return $this->costLock->acquire($this->resolveTenantId($productId, $companyId), $companyId, [$productId], function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId, $companyId, $variantId, $reason, $unitCost): StockMovement {
                $stockLevel = $this->lockStockLevel($productId, $locationId, $companyId, $variantId);

                /** @var numeric-string $quantityBefore */
                $quantityBefore = $stockLevel->quantity;
                $quantityAfter = bcadd($quantityBefore, $quantity, self::SCALE);

                $stockLevel->update(['quantity' => $quantityAfter]);

                $movement = $this->recordMovement(
                    tenantId: $stockLevel->tenant_id,
                    companyId: $stockLevel->company_id,
                    productId: $productId,
                    locationId: $locationId,
                    type: MovementType::Receipt,
                    quantity: $quantity,
                    quantityBefore: $quantityBefore,
                    quantityAfter: $quantityAfter,
                    reference: $reference,
                    userId: $userId,
                    variantId: $variantId,
                    reason: $reason,
                    unitCost: $unitCost,
                );

                // Record batch movement if batch ID provided
                if ($batchId !== null) {
                    $this->recordBatchMovement(
                        tenantId: $stockLevel->tenant_id,
                        batchId: $batchId,
                        locationId: $locationId,
                        movementId: $movement->id,
                        quantity: $quantity,
                    );
                } else {
                    $this->ensureDefaultBatchForImplicitPositiveStock($stockLevel, $productId);
                }

                // Dispatch StockMovementRecorded event after transaction commits
                $movementSnapshot = $movement;
                $tenantIdSnapshot = $stockLevel->tenant_id;
                $companyIdSnapshot = $stockLevel->company_id;
                $quantityAfterSnapshot = $quantityAfter;

                DB::afterCommit(function () use ($movementSnapshot, $tenantIdSnapshot, $companyIdSnapshot, $productId, $locationId, $quantity, $quantityAfterSnapshot, $reference, $variantId): void {
                    event(new StockMovementRecorded(
                        movementId: $movementSnapshot->id,
                        tenantId: $tenantIdSnapshot,
                        companyId: $companyIdSnapshot,
                        productId: $productId,
                        locationId: $locationId,
                        movementType: 'receipt',
                        quantity: $quantity,
                        unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $quantityAfterSnapshot,
                        reference: $reference,
                        occurredAt: now()->toIso8601String(),
                    ));

                    // V2 dual-dispatch (variant-aware). Fired alongside V1 so V1
                    // subscribers keep working; new subscribers read variantId.
                    event(new StockMovementRecordedV2(
                        movementId: $movementSnapshot->id,
                        tenantId: $tenantIdSnapshot,
                        companyId: $companyIdSnapshot,
                        productId: $productId,
                        locationId: $locationId,
                        movementType: 'receipt',
                        quantity: $quantity,
                        unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $quantityAfterSnapshot,
                        variantId: $variantId,
                        reference: $reference,
                        occurredAt: now()->toIso8601String(),
                    ));
                });

                return $movement;
            });
        }, attempts: 3);
    }

    /**
     * Issue stock from a location (e.g., for sales order).
     *
     * @param  numeric-string  $quantity
     * @param  numeric-string|null  $unitCost  Per-unit cost at COST_SCALE=6; when provided,
     *                                         unit_cost and total_cost are persisted on the
     *                                         movement row so reversals can recover the original
     *                                         cost without recomputing from a changed WAC.
     * @param  int|null  $batchId  Optional batch ID for batch-tracked products
     *
     * @throws InsufficientStockException
     */
    public function issue(
        string $productId,
        string $locationId,
        string $quantity,
        string $reference,
        string $userId,
        ?int $batchId = null,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
        ?MovementReason $reason = null,
        ?string $unitCost = null,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);

        // Pure decrement: NO advisory seam (mustNotLock). It mutates an existing
        // variant-scoped row via lockStockLevel()'s row lock, which serializes it
        // against any in-flight recompute holding that row.
        return DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId, $expectedCompanyId, $variantId, $reason, $unitCost): StockMovement {
            $stockLevel = $this->lockStockLevel($productId, $locationId, $expectedCompanyId ?? $this->resolveCompanyId($locationId), $variantId);

            /** @var numeric-string $available */
            $available = $stockLevel->getAvailableQuantity();

            if (bccomp($quantity, $available, self::SCALE) > 0) {
                throw new InsufficientStockException(
                    productId: $productId,
                    locationId: $locationId,
                    requested: $quantity,
                    available: $available,
                );
            }

            /** @var numeric-string $quantityBefore */
            $quantityBefore = $stockLevel->quantity;
            $quantityAfter = bcsub($quantityBefore, $quantity, self::SCALE);

            $stockLevel->update(['quantity' => $quantityAfter]);

            $movement = $this->recordMovement(
                tenantId: $stockLevel->tenant_id,
                companyId: $stockLevel->company_id,
                productId: $productId,
                locationId: $locationId,
                type: MovementType::Issue,
                quantity: $quantity,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                reference: $reference,
                userId: $userId,
                variantId: $variantId,
                reason: $reason,
                unitCost: $unitCost,
            );

            // Record batch movement if batch ID provided (negative quantity for issue)
            if ($batchId !== null) {
                $this->recordBatchMovement(
                    tenantId: $stockLevel->tenant_id,
                    batchId: $batchId,
                    locationId: $locationId,
                    movementId: $movement->id,
                    quantity: bcmul($quantity, '-1', 4),  // Negative for issue
                );
            }

            // Dispatch StockMovementRecorded event after transaction commits
            $movementSnapshot = $movement;
            $tenantIdSnapshot = $stockLevel->tenant_id;
            $companyIdSnapshot = $stockLevel->company_id;
            $quantityAfterSnapshot = $quantityAfter;

            DB::afterCommit(function () use ($movementSnapshot, $tenantIdSnapshot, $companyIdSnapshot, $productId, $locationId, $quantity, $quantityAfterSnapshot, $reference, $variantId): void {
                event(new StockMovementRecorded(
                    movementId: $movementSnapshot->id,
                    tenantId: $tenantIdSnapshot,
                    companyId: $companyIdSnapshot,
                    productId: $productId,
                    locationId: $locationId,
                    movementType: 'issue',
                    quantity: $quantity,
                    unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                    totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                    newStockLevel: $quantityAfterSnapshot,
                    reference: $reference,
                    occurredAt: now()->toIso8601String(),
                ));

                // V2 dual-dispatch (variant-aware).
                event(new StockMovementRecordedV2(
                    movementId: $movementSnapshot->id,
                    tenantId: $tenantIdSnapshot,
                    companyId: $companyIdSnapshot,
                    productId: $productId,
                    locationId: $locationId,
                    movementType: 'issue',
                    quantity: $quantity,
                    unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                    totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                    newStockLevel: $quantityAfterSnapshot,
                    variantId: $variantId,
                    reference: $reference,
                    occurredAt: now()->toIso8601String(),
                ));
            });

            return $movement;
        });
    }

    /**
     * Transfer stock between locations.
     *
     * @param  numeric-string  $quantity
     *
     * @throws InsufficientStockException
     */
    public function transfer(
        string $productId,
        string $fromLocationId,
        string $toLocationId,
        string $quantity,
        string $reference,
        string $userId,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
    ): void {
        $this->assertVariantConsistency($productId, $variantId);

        DB::transaction(function () use ($productId, $fromLocationId, $toLocationId, $quantity, $reference, $userId, $expectedCompanyId, $variantId): void {
            $resolvedCompanyId = $expectedCompanyId ?? $this->resolveCompanyId($fromLocationId);

            // ONE product across two locations — acquire the single product key
            // once (product-grain advisory; variant rows share the product cost,
            // §6.7), then row-lock both location rows under it.
            $this->costLock->acquire($this->resolveTenantId($productId, $resolvedCompanyId), $resolvedCompanyId, [$productId], function () use ($productId, $fromLocationId, $toLocationId, $quantity, $reference, $userId, $resolvedCompanyId, $variantId): void {
                // Lock source stock
                $sourceStock = $this->lockStockLevel($productId, $fromLocationId, $resolvedCompanyId, $variantId);

                /** @var numeric-string $available */
                $available = $sourceStock->getAvailableQuantity();

                if (bccomp($quantity, $available, self::SCALE) > 0) {
                    throw new InsufficientStockException(
                        productId: $productId,
                        locationId: $fromLocationId,
                        requested: $quantity,
                        available: $available,
                    );
                }

                // Deduct from source
                /** @var numeric-string $sourceQuantityBefore */
                $sourceQuantityBefore = $sourceStock->quantity;
                $sourceQuantityAfter = bcsub($sourceQuantityBefore, $quantity, self::SCALE);
                $sourceStock->update(['quantity' => $sourceQuantityAfter]);

                $sourceMovement = $this->recordMovement(
                    tenantId: $sourceStock->tenant_id,
                    companyId: $sourceStock->company_id,
                    productId: $productId,
                    locationId: $fromLocationId,
                    type: MovementType::TransferOut,
                    quantity: $quantity,
                    quantityBefore: $sourceQuantityBefore,
                    quantityAfter: $sourceQuantityAfter,
                    reference: $reference,
                    userId: $userId,
                    variantId: $variantId,
                );

                // Add to destination (lock the variant-scoped row — read-modify-write)
                $destStock = $this->lockStockLevel($productId, $toLocationId, $resolvedCompanyId, $variantId);
                /** @var numeric-string $destQuantityBefore */
                $destQuantityBefore = $destStock->quantity;
                $destQuantityAfter = bcadd($destQuantityBefore, $quantity, self::SCALE);
                $destStock->update(['quantity' => $destQuantityAfter]);

                $destMovement = $this->recordMovement(
                    tenantId: $destStock->tenant_id,
                    companyId: $destStock->company_id,
                    productId: $productId,
                    locationId: $toLocationId,
                    type: MovementType::TransferIn,
                    quantity: $quantity,
                    quantityBefore: $destQuantityBefore,
                    quantityAfter: $destQuantityAfter,
                    reference: $reference,
                    userId: $userId,
                    variantId: $variantId,
                );

                // Dispatch StockMovementRecorded events after transaction commits
                $sourceMovementSnapshot = $sourceMovement;
                $sourceTenantId = $sourceStock->tenant_id;
                $sourceCompanyId = $sourceStock->company_id;
                $sourceQuantityAfterSnapshot = $sourceQuantityAfter;

                $destMovementSnapshot = $destMovement;
                $destTenantId = $destStock->tenant_id;
                $destCompanyId = $destStock->company_id;
                $destQuantityAfterSnapshot = $destQuantityAfter;

                DB::afterCommit(function () use (
                    $sourceMovementSnapshot, $sourceTenantId, $sourceCompanyId, $productId, $fromLocationId, $quantity, $sourceQuantityAfterSnapshot, $reference,
                    $destMovementSnapshot, $destTenantId, $destCompanyId, $toLocationId, $destQuantityAfterSnapshot, $variantId,
                ): void {
                    event(new StockMovementRecorded(
                        movementId: $sourceMovementSnapshot->id,
                        tenantId: $sourceTenantId,
                        companyId: $sourceCompanyId,
                        productId: $productId,
                        locationId: $fromLocationId,
                        movementType: 'transfer_out',
                        quantity: $quantity,
                        unitCost: (string) ($sourceMovementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($sourceMovementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $sourceQuantityAfterSnapshot,
                        reference: $reference,
                        occurredAt: now()->toIso8601String(),
                    ));

                    event(new StockMovementRecorded(
                        movementId: $destMovementSnapshot->id,
                        tenantId: $destTenantId,
                        companyId: $destCompanyId,
                        productId: $productId,
                        locationId: $toLocationId,
                        movementType: 'transfer_in',
                        quantity: $quantity,
                        unitCost: (string) ($destMovementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($destMovementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $destQuantityAfterSnapshot,
                        reference: $reference,
                        occurredAt: now()->toIso8601String(),
                    ));

                    // V2 dual-dispatch (variant-aware) for both transfer legs.
                    event(new StockMovementRecordedV2(
                        movementId: $sourceMovementSnapshot->id,
                        tenantId: $sourceTenantId,
                        companyId: $sourceCompanyId,
                        productId: $productId,
                        locationId: $fromLocationId,
                        movementType: 'transfer_out',
                        quantity: $quantity,
                        unitCost: (string) ($sourceMovementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($sourceMovementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $sourceQuantityAfterSnapshot,
                        variantId: $variantId,
                        reference: $reference,
                        occurredAt: now()->toIso8601String(),
                    ));

                    event(new StockMovementRecordedV2(
                        movementId: $destMovementSnapshot->id,
                        tenantId: $destTenantId,
                        companyId: $destCompanyId,
                        productId: $productId,
                        locationId: $toLocationId,
                        movementType: 'transfer_in',
                        quantity: $quantity,
                        unitCost: (string) ($destMovementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($destMovementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $destQuantityAfterSnapshot,
                        variantId: $variantId,
                        reference: $reference,
                        occurredAt: now()->toIso8601String(),
                    ));
                });
            });
        }, attempts: 3);
    }

    /**
     * Reserve stock for an order (doesn't reduce quantity, but marks as reserved).
     *
     * @param  numeric-string  $quantity
     *
     * @throws InsufficientStockException
     */
    public function reserve(
        string $productId,
        string $locationId,
        string $quantity,
        string $reference,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
    ): void {
        $this->assertVariantConsistency($productId, $variantId);

        DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $expectedCompanyId, $variantId): void {
            $stockLevel = $this->lockStockLevel($productId, $locationId, $expectedCompanyId ?? $this->resolveCompanyId($locationId), $variantId);

            /** @var numeric-string $available */
            $available = $stockLevel->getAvailableQuantity();

            if (bccomp($quantity, $available, self::SCALE) > 0) {
                throw new InsufficientStockException(
                    productId: $productId,
                    locationId: $locationId,
                    requested: $quantity,
                    available: $available,
                );
            }

            /** @var numeric-string $reserved */
            $reserved = $stockLevel->reserved;
            $newReserved = bcadd($reserved, $quantity, self::SCALE);
            $stockLevel->update(['reserved' => $newReserved]);

            // Dispatch ReservationCreated event after transaction commits
            $reservationId = Str::uuid()->toString();
            $companyIdSnapshot = $stockLevel->company_id;

            DB::afterCommit(function () use ($reservationId, $companyIdSnapshot, $productId, $locationId, $quantity, $reference, $variantId): void {
                event(new ReservationCreated(
                    reservationId: $reservationId,
                    companyId: $companyIdSnapshot,
                    productId: $productId,
                    locationId: $locationId,
                    quantity: $quantity,
                    sourceType: 'reservation',
                    sourceId: $reference,
                    sourceLineId: null,
                    expiresAt: null,
                    priority: 0,
                    createdBy: '',
                    createdAt: now()->toIso8601String(),
                ));

                // V2 dual-dispatch (variant-aware).
                event(new ReservationCreatedV2(
                    reservationId: $reservationId,
                    companyId: $companyIdSnapshot,
                    productId: $productId,
                    locationId: $locationId,
                    quantity: $quantity,
                    sourceType: 'reservation',
                    sourceId: $reference,
                    sourceLineId: null,
                    expiresAt: null,
                    priority: 0,
                    createdBy: '',
                    createdAt: now()->toIso8601String(),
                    variantId: $variantId,
                ));
            });
        });
    }

    /**
     * Release a previous reservation.
     *
     * @param  numeric-string  $quantity
     */
    public function releaseReservation(
        string $productId,
        string $locationId,
        string $quantity,
        string $reference,
        ?string $expectedCompanyId = null,
    ): void {
        DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $expectedCompanyId): void {
            $stockLevel = $this->lockStockLevel($productId, $locationId, $expectedCompanyId ?? $this->resolveCompanyId($locationId));

            /** @var numeric-string $reserved */
            $reserved = $stockLevel->reserved;
            $newReserved = bcsub($reserved, $quantity, self::SCALE);
            if (bccomp($newReserved, '0.00', self::SCALE) < 0) {
                $newReserved = '0.00';
            }

            $stockLevel->update(['reserved' => $newReserved]);

            // Dispatch ReservationReleased event after transaction commits
            $reservationId = Str::uuid()->toString();
            $companyIdSnapshot = $stockLevel->company_id;

            DB::afterCommit(function () use ($reservationId, $companyIdSnapshot, $productId, $locationId, $quantity, $reference): void {
                event(new ReservationReleased(
                    reservationId: $reservationId,
                    companyId: $companyIdSnapshot,
                    productId: $productId,
                    locationId: $locationId,
                    quantity: $quantity,
                    sourceType: 'reservation',
                    sourceId: $reference,
                    releaseReason: 'manual_release',
                    releasedBy: null,
                    releasedAt: now()->toIso8601String(),
                ));

                // V2 dual-dispatch (variant-aware). This entry point does not
                // carry a $variantId (releaseReservation has no variant param —
                // it locates the row by product+location), so variantId is null.
                event(new ReservationReleasedV2(
                    reservationId: $reservationId,
                    companyId: $companyIdSnapshot,
                    productId: $productId,
                    locationId: $locationId,
                    quantity: $quantity,
                    sourceType: 'reservation',
                    sourceId: $reference,
                    releaseReason: 'manual_release',
                    releasedBy: null,
                    releasedAt: now()->toIso8601String(),
                    variantId: null,
                ));
            });
        });
    }

    /**
     * Adjust stock to a specific quantity (for inventory counts).
     *
     * @param  numeric-string  $newQuantity
     */
    public function adjust(
        string $productId,
        string $locationId,
        string $newQuantity,
        string $reason,
        string $userId,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
        ?MovementReason $reasonCode = null,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);

        return DB::transaction(function () use ($productId, $locationId, $newQuantity, $reason, $userId, $expectedCompanyId, $variantId, $reasonCode): StockMovement {
            $companyId = $expectedCompanyId ?? $this->resolveCompanyId($locationId);

            // WAC serialization seam (product-grain advisory key; §6.7).
            return $this->costLock->acquire($this->resolveTenantId($productId, $companyId), $companyId, [$productId], function () use ($productId, $locationId, $newQuantity, $reason, $userId, $companyId, $variantId, $reasonCode): StockMovement {
                $stockLevel = $this->lockStockLevel($productId, $locationId, $companyId, $variantId);

                /** @var numeric-string $quantityBefore */
                $quantityBefore = $stockLevel->quantity;
                $difference = bcsub($newQuantity, $quantityBefore, self::SCALE);

                $stockLevel->update(['quantity' => $newQuantity]);

                $movement = $this->recordMovement(
                    tenantId: $stockLevel->tenant_id,
                    companyId: $stockLevel->company_id,
                    productId: $productId,
                    locationId: $locationId,
                    type: MovementType::Adjustment,
                    quantity: $difference,
                    quantityBefore: $quantityBefore,
                    quantityAfter: $newQuantity,
                    reference: $reason,
                    userId: $userId,
                    variantId: $variantId,
                    reason: $reasonCode,
                );

                if (bccomp($difference, '0', self::SCALE) > 0) {
                    $this->ensureDefaultBatchForImplicitPositiveStock($stockLevel, $productId);
                }

                // Dispatch StockMovementRecorded event after transaction commits
                $movementSnapshot = $movement;
                $tenantIdSnapshot = $stockLevel->tenant_id;
                $companyIdSnapshot = $stockLevel->company_id;

                DB::afterCommit(function () use ($movementSnapshot, $tenantIdSnapshot, $companyIdSnapshot, $productId, $locationId, $difference, $newQuantity, $reason, $variantId): void {
                    event(new StockMovementRecorded(
                        movementId: $movementSnapshot->id,
                        tenantId: $tenantIdSnapshot,
                        companyId: $companyIdSnapshot,
                        productId: $productId,
                        locationId: $locationId,
                        movementType: 'adjustment',
                        quantity: $difference,
                        unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $newQuantity,
                        reference: $reason,
                        occurredAt: now()->toIso8601String(),
                    ));

                    // V2 dual-dispatch (variant-aware).
                    event(new StockMovementRecordedV2(
                        movementId: $movementSnapshot->id,
                        tenantId: $tenantIdSnapshot,
                        companyId: $companyIdSnapshot,
                        productId: $productId,
                        locationId: $locationId,
                        movementType: 'adjustment',
                        quantity: $difference,
                        unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                        totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                        newStockLevel: $newQuantity,
                        variantId: $variantId,
                        reference: $reason,
                        occurredAt: now()->toIso8601String(),
                    ));
                });

                return $movement;
            });
        }, attempts: 3);
    }

    /**
     * Get or create a stock level record for a product at a location.
     *
     * Scopes both the Location and Product lookups to $expectedCompanyId so a
     * forged cross-company locationId or productId can never seed a StockLevel
     * that mixes one company's product with another company's location. Throws
     * ModelNotFoundException if either the location or the product does not
     * belong to $expectedCompanyId.
     */
    private function getOrCreateStockLevel(
        string $productId,
        string $locationId,
        string $expectedCompanyId,
        ?string $variantId = null,
    ): StockLevel {
        $location = Location::query()
            ->where('company_id', $expectedCompanyId)
            ->findOrFail($locationId);
        $product = Product::query()
            ->where('company_id', $location->company_id)
            ->findOrFail($productId);

        // Scoping the firstOrCreate key on variant_id (NULL for product-level)
        // honours the partial unique indexes on stock_levels: one row per
        // (tenant, product, location) when variant_id IS NULL, and one per
        // (tenant, product, variant, location) when variant_id IS NOT NULL.
        return StockLevel::firstOrCreate(
            [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
            ],
            [
                'tenant_id' => $product->tenant_id,
                'company_id' => $location->company_id,
                'quantity' => '0.00',
                'reserved' => '0.00',
            ]
        );
    }

    /**
     * Lock stock level for update (pessimistic locking).
     */
    private function lockStockLevel(
        string $productId,
        string $locationId,
        string $expectedCompanyId,
        ?string $variantId = null,
    ): StockLevel {
        $stockLevel = StockLevel::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $expectedCompanyId)
            ->when(
                $variantId !== null,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->whereNull('variant_id'),
            )
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            // Create with zero quantity if doesn't exist
            $stockLevel = $this->getOrCreateStockLevel($productId, $locationId, $expectedCompanyId, $variantId);

            // Re-lock
            return StockLevel::query()
                ->where('id', $stockLevel->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $stockLevel;
    }

    /**
     * Resolve the company_id for a location when not provided by caller.
     *
     * Falls back to an unscoped Location fetch when the caller cannot supply
     * a company_id (e.g., legacy call sites that pre-date this contract).
     * New callers SHOULD pass expectedCompanyId directly to avoid this path.
     */
    private function resolveCompanyId(string $locationId): string
    {
        return Location::findOrFail($locationId)->company_id;
    }

    /**
     * Resolve the owning tenant_id for the ProductCostLock key tuple.
     *
     * The lock key MUST be (tenant_id, company_id, product_id) — the SAME tuple
     * WeightedAverageCostService::recordCostAdjustment uses. Location carries
     * only company_id, so the tenant is resolved from the Product (scoped to the
     * already-validated company) rather than the location. The key is
     * product-grain even for variant-scoped rows: variant cost is advisory only,
     * WAC stays product-grain (§6.7).
     */
    private function resolveTenantId(string $productId, string $companyId): string
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->findOrFail($productId)
            ->tenant_id;
    }

    private function ensureDefaultBatchForImplicitPositiveStock(StockLevel $stockLevel, string $productId): void
    {
        $product = Product::query()
            ->where('company_id', $stockLevel->company_id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            return;
        }

        $this->batchStockService->ensureDefaultBatch(
            companyId: $stockLevel->company_id,
            tenantId: $stockLevel->tenant_id,
            productId: $productId,
            locationId: $stockLevel->location_id,
            targetQuantity: (string) $stockLevel->quantity,
            shelfLifeDays: $product->default_shelf_life_days,
            asOfDate: now()->toDateString(),
            variantId: $stockLevel->variant_id,
        );
    }

    /**
     * Record a stock movement.
     *
     * @param  numeric-string  $quantity
     * @param  numeric-string  $quantityBefore
     * @param  numeric-string  $quantityAfter
     * @param  numeric-string|null  $unitCost  Per-unit cost at COST_SCALE=6; when provided,
     *                                         unit_cost and total_cost are persisted so
     *                                         Phase C reversals can recover the original cost
     *                                         from the row itself (not from a now-changed WAC).
     *                                         Mirrors the WeightedAverageCostService precision:
     *                                         total_cost = bcmul(unitCost, quantity, COST_SCALE).
     */
    private function recordMovement(
        string $tenantId,
        string $companyId,
        string $productId,
        string $locationId,
        MovementType $type,
        string $quantity,
        string $quantityBefore,
        string $quantityAfter,
        string $reference,
        string $userId,
        ?string $variantId = null,
        ?MovementReason $reason = null,
        ?string $unitCost = null,
    ): StockMovement {
        // Scope the Location lookup to $companyId (derived from the upstream
        // trusted StockLevel). A forged locationId from another company would
        // fail the company_id predicate and throw ModelNotFoundException.
        $location = Location::query()
            ->where('company_id', $companyId)
            ->findOrFail($locationId);

        // Persist cost columns at COST_SCALE=6 — the same internal precision used
        // by WeightedAverageCostService — so Phase C reversals can recover the
        // original write-off cost from the row itself. total_cost is derived via
        // bcmath; no native float arithmetic is used.
        $persistedUnitCost = null;
        $persistedTotalCost = null;
        if ($unitCost !== null) {
            $persistedUnitCost = bcadd($unitCost, '0', self::COST_SCALE);
            $persistedTotalCost = bcmul($unitCost, $quantity, self::COST_SCALE);
        }

        return StockMovement::create([
            'tenant_id' => $tenantId,
            'company_id' => $location->company_id,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'movement_type' => $type,
            'reason' => $reason,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'unit_cost' => $persistedUnitCost,
            'total_cost' => $persistedTotalCost,
            'reference' => $reference,
            'user_id' => $userId,
        ]);
    }

    /**
     * Enforce the no-mixed-mode invariant.
     *
     * If the caller addresses a product at the product level ($variantId === null)
     * but that product has at least one ACTIVE variant, the stock operation is
     * ambiguous and must be rejected — variant-bearing products must always be
     * addressed by a concrete variant. When an explicit $variantId is supplied,
     * the operation is unambiguous and no check is required (the FK + partial
     * unique index on stock_levels guard correctness).
     *
     * @throws VariantRequiredException when a variant-bearing product is addressed product-level.
     */
    private function assertVariantConsistency(string $productId, ?string $variantId): void
    {
        if ($variantId !== null) {
            return;
        }

        if ($this->variantLookup->listForProduct($productId, true)->isNotEmpty()) {
            throw VariantRequiredException::forProduct($productId);
        }
    }

    /**
     * Record a batch-level stock movement and update batch stock.
     *
     * @param  numeric-string  $quantity
     */
    private function recordBatchMovement(
        string $tenantId,
        int $batchId,
        string $locationId,
        string $movementId,
        string $quantity,
    ): void {
        // Create batch movement record
        BatchMovement::create([
            'tenant_id' => $tenantId,
            'batch_id' => $batchId,
            'movement_id' => $movementId,
            'quantity' => $quantity,
        ]);

        // Update batch stock level
        $batchStock = BatchStock::firstOrCreate(
            [
                'batch_id' => $batchId,
                'location_id' => $locationId,
            ],
            [
                'tenant_id' => $tenantId,
                'quantity' => '0.0000',
                'reserved_quantity' => '0.0000',
            ]
        );

        /** @var numeric-string $currentQuantity */
        $currentQuantity = $batchStock->quantity;
        $newQuantity = bcadd($currentQuantity, $quantity, 4);

        $batchStock->update(['quantity' => $newQuantity]);
    }
}
