<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\DTOs\ReplayAuditDto;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationCreatedV2;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\Inventory\Domain\Events\ReservationReleasedV2;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\Exceptions\AdjustmentExceedsAvailableException;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Exceptions\StockMovedSinceAuthoringException;
use App\Modules\Inventory\Domain\InventoryScale;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use App\Shared\Domain\Exceptions\VariantRequiredException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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

    /**
     * Reference stamped on replay-based count-finalize movements. The counting
     * number is not threaded through the fixed applyCountResult() signature; the
     * item's replay_audit + expected_qty_at_apply carry the per-line trail.
     */
    private const COUNT_REPLAY_REFERENCE = 'COUNT_REPLAY';

    public function __construct(
        private readonly ProductVariantLookup $variantLookup,
        private readonly ProductCostLock $costLock,
        private readonly BatchStockService $batchStockService,
        private readonly MovementReplayService $replayService,
        private readonly FirstCountDetector $firstCountDetector,
        private readonly WeightedAverageCostService $weightedAverageCostService,
        private readonly CountingReplayGuardEvaluator $countingReplayGuardEvaluator,
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
     * @param  bool  $creditsLotItself  Set by a caller that credits the lot ledger
     *                                  ITSELF, right after this call, through
     *                                  `BatchStockService::receiveBatchStock()`.
     *                                  Suppresses the implicit DEFAULT-lot top-up:
     *                                  at THIS point the incoming units are not yet
     *                                  inside any lot, so the untracked-remainder
     *                                  helper would back them with a DEFAULT lot and
     *                                  the caller's own credit would then book them a
     *                                  SECOND time (gate r1 finding 8). Not the same
     *                                  as `$batchId`: that one asks THIS method to
     *                                  write the lot leg.
     * @param  StockMovementReferenceType|null  $referenceType  Source-document morph type; pass together with $referenceId
     * @param  string|null  $referenceId  Source-document UUID; pass together with $referenceType
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
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
        bool $creditsLotItself = false,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId, $expectedCompanyId, $variantId, $reason, $unitCost, $referenceType, $referenceId, $creditsLotItself): StockMovement {
            $companyId = $expectedCompanyId ?? $this->resolveCompanyId($locationId);

            // WAC serialization seam: take the per-product advisory lock FIRST
            // (canonical order advisory -> stock_level -> product). The advisory
            // key is product-grain ([$productId]) even when the row we touch is
            // variant-scoped — variant cost is advisory only; WAC stays
            // product-grain (§6.7).
            return $this->costLock->acquire($this->resolveTenantId($productId, $companyId), $companyId, [$productId], function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId, $companyId, $variantId, $reason, $unitCost, $referenceType, $referenceId, $creditsLotItself): StockMovement {
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
                    referenceType: $referenceType,
                    referenceId: $referenceId,
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
                } elseif (! $creditsLotItself) {
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
                        referenceType: $movementSnapshot->reference_type,
                        referenceId: $movementSnapshot->reference_id,
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
                        referenceType: $movementSnapshot->reference_type,
                        referenceId: $movementSnapshot->reference_id,
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
     * @param  string|null  $userId  Acting user, or null for a document-driven exit with no
     *                               interactive actor. `stock_movements.user_id` is a NULLABLE
     *                               FK, and recordMovement() has always accepted null; the
     *                               parameter was needlessly narrowed here, which forced a
     *                               document-path caller (DPA V8's supplier goods-return note
     *                               confirm) to invent a fake actor or pass '' into a uuid column.
     * @param  StockMovementReferenceType|null  $referenceType  Source-document morph type; pass together with $referenceId
     * @param  string|null  $referenceId  Source-document UUID; pass together with $referenceType
     * @param  CarbonInterface|null  $occurredAt  Business event time; POS-originated callers thread the
     *                                            DEVICE event time here. Defaults to now() (server clock).
     *
     * @throws InsufficientStockException
     */
    public function issue(
        string $productId,
        string $locationId,
        string $quantity,
        string $reference,
        ?string $userId,
        ?int $batchId = null,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
        ?MovementReason $reason = null,
        ?string $unitCost = null,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);

        // Pure decrement: NO advisory seam (mustNotLock). It mutates an existing
        // variant-scoped row via lockStockLevel()'s row lock, which serializes it
        // against any in-flight recompute holding that row.
        return DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId, $expectedCompanyId, $variantId, $reason, $unitCost, $referenceType, $referenceId, $occurredAt): StockMovement {
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
                occurredAt: $occurredAt,
                referenceType: $referenceType,
                referenceId: $referenceId,
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
                    referenceType: $movementSnapshot->reference_type,
                    referenceId: $movementSnapshot->reference_id,
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
                    referenceType: $movementSnapshot->reference_type,
                    referenceId: $movementSnapshot->reference_id,
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
     * @param  StockMovementReferenceType|null  $referenceType  Source-document morph type; pass
     *                                                          together with $referenceId. Stamped on BOTH transfer legs.
     * @param  string|null  $referenceId  Source-document UUID; pass together with $referenceType
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
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
    ): void {
        $this->assertVariantConsistency($productId, $variantId);
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);

        DB::transaction(function () use ($productId, $fromLocationId, $toLocationId, $quantity, $reference, $userId, $expectedCompanyId, $variantId, $referenceType, $referenceId): void {
            $resolvedCompanyId = $expectedCompanyId ?? $this->resolveCompanyId($fromLocationId);

            // ONE product across two locations — acquire the single product key
            // once (product-grain advisory; variant rows share the product cost,
            // §6.7), then row-lock both location rows under it.
            $this->costLock->acquire($this->resolveTenantId($productId, $resolvedCompanyId), $resolvedCompanyId, [$productId], function () use ($productId, $fromLocationId, $toLocationId, $quantity, $reference, $userId, $resolvedCompanyId, $variantId, $referenceType, $referenceId): void {
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
                    referenceType: $referenceType,
                    referenceId: $referenceId,
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
                    referenceType: $referenceType,
                    referenceId: $referenceId,
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
                        referenceType: $sourceMovementSnapshot->reference_type,
                        referenceId: $sourceMovementSnapshot->reference_id,
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
                        referenceType: $destMovementSnapshot->reference_type,
                        referenceId: $destMovementSnapshot->reference_id,
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
                        referenceType: $sourceMovementSnapshot->reference_type,
                        referenceId: $sourceMovementSnapshot->reference_id,
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
                        referenceType: $destMovementSnapshot->reference_type,
                        referenceId: $destMovementSnapshot->reference_id,
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
     * ABSOLUTE entry point. Kept for the counting listener
     * (ApplyStockAdjustmentsOnCountingCompleted::applyLegacyDelta), which
     * legitimately holds an absolute. Manual corrections go through
     * {@see self::adjustByDelta()} instead: writing a client-supplied absolute
     * silently overwrites anything committed between the browser read and the
     * POST (DPA V7 / D1).
     *
     * The `costLock->acquire` call stays TEXTUALLY in this body — it is pinned
     * there by InventoryCostLockCoverageTest, which greps the balanced-brace
     * body of this signature.
     *
     * @param  numeric-string  $newQuantity
     * @param  StockMovementReferenceType|null  $referenceType  Source-document morph type; pass together with $referenceId
     * @param  string|null  $referenceId  Source-document UUID; pass together with $referenceType
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
        ?CarbonInterface $occurredAt = null,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);

        return DB::transaction(function () use ($productId, $locationId, $newQuantity, $reason, $userId, $expectedCompanyId, $variantId, $reasonCode, $occurredAt, $referenceType, $referenceId): StockMovement {
            $companyId = $expectedCompanyId ?? $this->resolveCompanyId($locationId);

            // WAC serialization seam (product-grain advisory key; §6.7).
            return $this->costLock->acquire($this->resolveTenantId($productId, $companyId), $companyId, [$productId], function () use ($productId, $locationId, $newQuantity, $reason, $userId, $companyId, $variantId, $reasonCode, $occurredAt, $referenceType, $referenceId): StockMovement {
                $stockLevel = $this->lockStockLevel($productId, $locationId, $companyId, $variantId);

                /** @var numeric-string $quantityBefore */
                $quantityBefore = $stockLevel->quantity;
                /** @var numeric-string $difference */
                $difference = bcsub($newQuantity, $quantityBefore, self::SCALE);

                return $this->postAdjustmentWithinLock(
                    stockLevel: $stockLevel,
                    productId: $productId,
                    locationId: $locationId,
                    difference: $difference,
                    quantityBefore: $quantityBefore,
                    newQuantity: $newQuantity,
                    reference: $reason,
                    userId: $userId,
                    variantId: $variantId,
                    reasonCode: $reasonCode,
                    occurredAt: $occurredAt,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    batchId: null,
                    reversesMovementId: null,
                    // The LEGACY absolute path keeps the TARGET-based default-lot
                    // reconciliation. See postAdjustmentWithinLock()'s docblock:
                    // the counting listener drives this method, and that lane's
                    // invariant is "reconcile the lot UP TO the aggregate".
                    deltaBasedDefaultLot: false,
                );
            });
        }, attempts: 3);
    }

    /**
     * Apply a SIGNED delta to a stock line — the delta-native entry point the
     * `stock_adjustments` document posts through (DPA V7 / D1).
     *
     * Why a second public method rather than a flag on adjust(): the absolute
     * form is unfixable. It reads `quantity_before` under the lock and then
     * writes the CLIENT's absolute, so any concurrent movement is silently
     * discarded. A delta is applied to whatever the row actually holds, and the
     * operator's authoring snapshot becomes an explicit, overridable assertion
     * (`$observedBefore`) instead of a hidden overwrite.
     *
     * Three guards, all INSIDE the advisory lock and after `lockStockLevel()` —
     * the only point at which the row is authoritative:
     *  1. non-zero delta (a zero delta is not a correction);
     *  2. STALENESS — `$observedBefore` vs the locked `quantity_before`,
     *     overridable via `$acknowledgeStale` (D15);
     *  3. AVAILABILITY — reserved-aware, negative deltas only, overridable via
     *     `$ignoreReservations` (D1a). This reproduces the boundary of the
     *     `issue()` endpoint V7 deletes.
     *
     * The `costLock->acquire` call stays TEXTUALLY in this body for the same
     * architecture-test reason as adjust().
     *
     * @param  numeric-string  $deltaQuantity  SIGNED; must not be zero
     * @param  numeric-string|null  $observedBefore  The operator's authoring snapshot; null skips the staleness check
     * @param  int|null  $batchId  Lot to move together with the aggregate (D1b)
     * @param  string|null  $reversesMovementId  The movement this line contra-corrects (D8)
     * @param  StockMovementReferenceType|null  $referenceType  Source-document morph type; pass together with $referenceId
     * @param  string|null  $referenceId  Source-document UUID; pass together with $referenceType
     *
     * @throws StockMovedSinceAuthoringException
     * @throws AdjustmentExceedsAvailableException
     */
    public function adjustByDelta(
        string $productId,
        string $locationId,
        string $deltaQuantity,
        string $reference,
        string $userId,
        ?string $expectedCompanyId = null,
        ?string $variantId = null,
        ?MovementReason $reasonCode = null,
        ?CarbonInterface $occurredAt = null,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
        ?string $observedBefore = null,
        bool $acknowledgeStale = false,
        bool $ignoreReservations = false,
        ?int $batchId = null,
        ?string $reversesMovementId = null,
    ): StockMovement {
        $this->assertVariantConsistency($productId, $variantId);
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);
        $this->assertNonZeroDelta($deltaQuantity);

        return DB::transaction(function () use ($productId, $locationId, $deltaQuantity, $reference, $userId, $expectedCompanyId, $variantId, $reasonCode, $occurredAt, $referenceType, $referenceId, $observedBefore, $acknowledgeStale, $ignoreReservations, $batchId, $reversesMovementId): StockMovement {
            $companyId = $expectedCompanyId ?? $this->resolveCompanyId($locationId);

            // WAC serialization seam (product-grain advisory key; §6.7). The key
            // tuple MUST be resolved through resolveTenantId() — the same source
            // StockAdjustmentDocumentService::post() uses for its up-front sorted
            // acquire, or the two would hash different strings and the deadlock
            // defence would evaporate silently (D14a).
            return $this->costLock->acquire($this->resolveTenantId($productId, $companyId), $companyId, [$productId], function () use ($productId, $locationId, $deltaQuantity, $reference, $userId, $companyId, $variantId, $reasonCode, $occurredAt, $referenceType, $referenceId, $observedBefore, $acknowledgeStale, $ignoreReservations, $batchId, $reversesMovementId): StockMovement {
                $stockLevel = $this->lockStockLevel($productId, $locationId, $companyId, $variantId);

                /** @var numeric-string $quantityBefore */
                $quantityBefore = $stockLevel->quantity;

                // STALENESS — inside the lock, the only place $quantityBefore is
                // authoritative (D15). Checking it in the application service
                // before the writer would reproduce the race verbatim: no row
                // lock is held there.
                if ($observedBefore !== null
                    && ! $acknowledgeStale
                    && bccomp($observedBefore, $quantityBefore, self::SCALE) !== 0) {
                    throw new StockMovedSinceAuthoringException(
                        productId: $productId,
                        locationId: $locationId,
                        variantId: $variantId,
                        batchUuid: $this->resolveBatchUuid($batchId),
                        observedBefore: $observedBefore,
                        quantityBefore: $quantityBefore,
                        quantityDecimals: $this->resolveQuantityDecimals($productId, $companyId),
                    );
                }

                // AVAILABILITY — reserved-aware, negative deltas ONLY (D1a).
                if (bccomp($deltaQuantity, '0', self::SCALE) < 0 && ! $ignoreReservations) {
                    /** @var numeric-string $available */
                    $available = $stockLevel->getAvailableQuantity();

                    if (bccomp(bcadd($available, $deltaQuantity, self::SCALE), '0', self::SCALE) < 0) {
                        throw new AdjustmentExceedsAvailableException(
                            productId: $productId,
                            locationId: $locationId,
                            quantityBefore: $quantityBefore,
                            reserved: (string) $stockLevel->reserved,
                            available: $available,
                            deltaQuantity: $deltaQuantity,
                            quantityDecimals: $this->resolveQuantityDecimals($productId, $companyId),
                        );
                    }
                }

                /** @var numeric-string $quantityAfter */
                $quantityAfter = bcadd($quantityBefore, $deltaQuantity, self::SCALE);

                return $this->postAdjustmentWithinLock(
                    stockLevel: $stockLevel,
                    productId: $productId,
                    locationId: $locationId,
                    difference: $deltaQuantity,
                    quantityBefore: $quantityBefore,
                    newQuantity: $quantityAfter,
                    reference: $reference,
                    userId: $userId,
                    variantId: $variantId,
                    reasonCode: $reasonCode,
                    occurredAt: $occurredAt,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    batchId: $batchId,
                    reversesMovementId: $reversesMovementId,
                    deltaBasedDefaultLot: true,
                );
            });
        }, attempts: 3);
    }

    /**
     * The shared inside-the-lock body of adjust() / adjustByDelta().
     *
     * MUST be called with the ProductCostLock held and the stock_level row
     * locked FOR UPDATE. It writes the aggregate, records the movement, moves
     * the lot, and dispatches the V1+V2 events after commit.
     *
     * The lot branch is the ONE deliberate change from the pre-V7 adjust()
     * body, which called `ensureDefaultBatchForImplicitPositiveStock()`
     * unconditionally for a positive difference. That helper is TARGET-based —
     * it tops the DEFAULT lot up to the post-update AGGREGATE — so on a product
     * already holding real lots a `+5` inflated DEFAULT to the whole aggregate
     * (Σ lots > aggregate), and a negative difference moved no lot at all
     * (Σ lots > aggregate again). Both corrupt FEFO (D1b / gate C2).
     *
     * Lock order is `stock_levels` → `inventory_batch_stock`, matching every
     * existing batch consumer (BatchWriteOffService), so no AB-BA cycle is
     * introduced. Neither BatchStockService method touches aggregate stock
     * levels, so pairing them with the `$stockLevel->update()` above does not
     * double-count.
     *
     * ⚠ `$deltaBasedDefaultLot` resolves a CONTRADICTION inside plan V7 and is
     * flagged for a gate ruling (task-v7-report.md, "Collision C-1"). D1 states
     * that adjust() "passes batchId: null so its behaviour is bit-for-bit
     * unchanged"; D1b part 3 states that this shared body replaces adjust()'s
     * `ensureDefaultBatchForImplicitPositiveStock()` call with the four-way
     * branch. Those cannot both hold. Reality settles it: adjust() is driven by
     * ApplyStockAdjustmentsOnCountingCompleted, and the counting lane's shipped
     * invariant (InventoryCountingDefaultBatchTest) is TARGET-based — count a
     * batch-tracked line from an aggregate of 5 with zero lots up to 7 and the
     * DEFAULT lot must hold 7, not 2. Delta-based reconciliation there would
     * leave 5 units outside any lot. So the LEGACY absolute path keeps the
     * target-based helper (D1's reading) and only the delta-native path — the
     * one C2 is actually about, since it is "the only manual route" — gets the
     * delta-based method. C2 is fully addressed for the document either way.
     *
     * @param  numeric-string  $difference  SIGNED delta
     * @param  numeric-string  $quantityBefore
     * @param  numeric-string  $newQuantity  Resulting absolute
     */
    private function postAdjustmentWithinLock(
        StockLevel $stockLevel,
        string $productId,
        string $locationId,
        string $difference,
        string $quantityBefore,
        string $newQuantity,
        string $reference,
        string $userId,
        ?string $variantId,
        ?MovementReason $reasonCode,
        ?CarbonInterface $occurredAt,
        ?StockMovementReferenceType $referenceType,
        ?string $referenceId,
        ?int $batchId,
        ?string $reversesMovementId,
        bool $deltaBasedDefaultLot,
    ): StockMovement {
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
            reference: $reference,
            userId: $userId,
            variantId: $variantId,
            reason: $reasonCode,
            // T21 / D-20 half-fix. BOTH callers get the row cost:
            //  - adjust() is the LEGACY counting path, and its count_correction
            //    must share the replay path's one basis (see postCountCorrection);
            //  - adjustByDelta() is the stock_adjustments DOCUMENT path, which
            //    gets the cost so a Damage/WriteOff line stops being
            //    indistinguishable from D-b's zero-cost population — WITHOUT a GL
            //    leg, which D-20 still forbids. That refusal is STRUCTURAL here:
            //    this body has no GL sink at all, so no adjustment document can
            //    reach the inventory posting buffer. D-a/D-b's
            //    `reference_type = 'stock_adjustment'` exclusion keeps the
            //    now-costed document lines out of the detector.
            unitCost: $this->resolveRowUnitCost($productId, $stockLevel->company_id),
            occurredAt: $occurredAt,
            referenceType: $referenceType,
            referenceId: $referenceId,
            reversesMovementId: $reversesMovementId,
        );

        $isPositive = bccomp($difference, '0', self::SCALE) > 0;

        if ($batchId !== null && $isPositive) {
            $this->batchStockService->receiveBatchStock(
                tenantId: $stockLevel->tenant_id,
                batchId: $batchId,
                locationId: $locationId,
                quantity: $difference,
                movementId: $movement->id,
            );
        } elseif ($batchId !== null) {
            // Strict, lockForUpdate, InsufficientBatchStockException-with-shortfall.
            // It also checks available_quantity, so lot-level reservations are
            // honoured — consistent with the aggregate guard in D1a.
            $this->batchStockService->issueBatchStock(
                tenantId: $stockLevel->tenant_id,
                batchId: $batchId,
                locationId: $locationId,
                quantity: bcmul($difference, '-1', self::SCALE),
                movementId: $movement->id,
            );
        } elseif ($isPositive && $deltaBasedDefaultLot) {
            // No lot named. For a batch-tracked product the found stock still has
            // to land in a lot, by the DELTA (D1b part 4).
            $this->receiveIntoDefaultBatchByDelta($stockLevel, $productId, $difference, $movement->id);
        } elseif ($isPositive) {
            $this->ensureDefaultBatchForImplicitPositiveStock($stockLevel, $productId);
        } elseif ($deltaBasedDefaultLot) {
            // $batchId === null && $difference < 0 on the DOCUMENT path.
            //
            // REACHABLE, and the two ways matter (gate N-6 — a stale
            // reachability claim here is what let C-1's arm rot, so this one
            // states the truth rather than a hope):
            //
            //  1. a batch-tracked product whose lots are all empty — the document
            //     refuses a lot-less negative only when a lot HOLDS STOCK here;
            //  2. a CONTRA line, which is exempt from lot-required because it
            //     inherits the disposition of an original that moved no lot
            //     (StockAdjustmentDocumentService::resolveBatchId()).
            //
            // Draining the DEFAULT lot is therefore a real path, not a
            // theoretical backstop. It cannot overshoot (see the method) and it
            // strictly narrows any gap.
            $this->issueFromDefaultBatchByDelta($stockLevel, $productId, $difference, $movement->id);
        } else {
            // The LEGACY absolute adjust() reaching a lot-less negative. Its
            // ONE caller is the legacy counting path
            // (ApplyStockAdjustmentsOnCountingCompleted::applyLegacyDelta), and
            // campaign W4-6 established that a count shortage must move the lot
            // ledger with the aggregate or Σ lots drifts permanently above it.
            // Same FEFO, same tolerance as the replay path's own arm.
            $this->drawLotsDownForCountShortage($stockLevel, $productId, $difference, $movement->id);
        }

        // Dispatch StockMovementRecorded event after transaction commits
        $movementSnapshot = $movement;
        $tenantIdSnapshot = $stockLevel->tenant_id;
        $companyIdSnapshot = $stockLevel->company_id;

        DB::afterCommit(function () use ($movementSnapshot, $tenantIdSnapshot, $companyIdSnapshot, $productId, $locationId, $difference, $newQuantity, $reference, $variantId): void {
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
                reference: $reference,
                referenceType: $movementSnapshot->reference_type,
                referenceId: $movementSnapshot->reference_id,
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
                reference: $reference,
                referenceType: $movementSnapshot->reference_type,
                referenceId: $movementSnapshot->reference_id,
                occurredAt: now()->toIso8601String(),
            ));
        });

        return $movement;
    }

    /**
     * DELTA-based twin of ensureDefaultBatchForImplicitPositiveStock (D1b part 4).
     *
     * Deliberately a NEW private method rather than a fix to the shared helper:
     * the helper is also called by receive(), and ReverseWriteOffService calls
     * `receive(batchId: null)` and THEN `receiveBatchStock` on the original lot —
     * so making the shared helper delta-based would turn its current over-count
     * into a DOUBLE-count on the write-off reversal path. That interaction is a
     * pre-existing defect V7 discovered and does not own (plan §5).
     *
     * @param  numeric-string  $delta  Positive delta to land in the DEFAULT lot
     */
    private function receiveIntoDefaultBatchByDelta(
        StockLevel $stockLevel,
        string $productId,
        string $delta,
        string $movementId,
    ): void {
        $product = Product::query()
            ->where('company_id', $stockLevel->company_id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            return;
        }

        $asOfDate = now()->toDateString();
        $shelfLifeDays = $product->default_shelf_life_days ?? BatchStockService::DEFAULT_SHELF_LIFE_DAYS;

        $batch = $this->batchStockService->findOrCreateBatch(
            companyId: $stockLevel->company_id,
            tenantId: $stockLevel->tenant_id,
            productId: $productId,
            batchNumber: BatchStockService::DEFAULT_BATCH_NUMBER,
            expiryDate: Carbon::parse($asOfDate)->addDays($shelfLifeDays)->toDateString(),
            manufacturingDate: $asOfDate,
            variantId: $stockLevel->variant_id,
        );

        $this->batchStockService->receiveBatchStock(
            tenantId: $stockLevel->tenant_id,
            batchId: $batch->id,
            locationId: $stockLevel->location_id,
            quantity: $delta,
            movementId: $movementId,
        );
    }

    /**
     * Draw a lot-less negative down from the DEFAULT lot (gate code-review C-1).
     *
     * The mirror of receiveIntoDefaultBatchByDelta(). Deliberately TOLERANT
     * rather than a second refusal surface: turning a correction into a hard
     * failure here would strand the operator with an aggregate that already
     * moved.
     *
     * Tolerant does NOT mean silent. When the DEFAULT lot holds less than the
     * magnitude, it drains what IS there instead of touching nothing (gate N-2):
     * no-op'ing left Sigma lots ABOVE the aggregate — the very FEFO corruption
     * this arm exists to prevent — for exactly the hypothetical caller the arm
     * exists to protect against. Draining what exists cannot overshoot and
     * strictly narrows the gap.
     *
     * Scope claim, stated precisely: under the document's own predicates this arm
     * is unreachable while a stocked lot exists, so it is a BACKSTOP, not the
     * mechanism that maintains the invariant. The invariant is maintained by
     * resolveBatchId()/assertLinePredicatesAtPost(); this keeps a future caller
     * that forgets them from corrupting FEFO outright.
     *
     * @param  numeric-string  $delta  Negative delta being applied to the aggregate
     */
    private function issueFromDefaultBatchByDelta(
        StockLevel $stockLevel,
        string $productId,
        string $delta,
        string $movementId,
    ): void {
        $product = Product::query()
            ->where('company_id', $stockLevel->company_id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            return;
        }

        $batch = Batch::query()
            ->where('company_id', $stockLevel->company_id)
            ->where('product_id', $productId)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->when(
                $stockLevel->variant_id === null,
                static fn ($query) => $query->whereNull('variant_id'),
                static fn ($query) => $query->where('variant_id', $stockLevel->variant_id),
            )
            ->first();

        if ($batch === null) {
            return;
        }

        /** @var numeric-string $available */
        $available = (string) (BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $stockLevel->location_id)
            ->value('quantity') ?? '0');

        /** @var numeric-string $magnitude */
        $magnitude = bcmul($delta, '-1', self::SCALE);

        // Drain what IS there when the lot is short. Returning early left the
        // aggregate moved and the lot untouched (gate N-2).
        /** @var numeric-string $drain */
        $drain = bccomp($available, $magnitude, self::SCALE) < 0 ? $available : $magnitude;

        if (bccomp($drain, '0', self::SCALE) <= 0) {
            return;
        }

        $this->batchStockService->issueBatchStock(
            tenantId: $stockLevel->tenant_id,
            batchId: $batch->id,
            locationId: $stockLevel->location_id,
            quantity: $drain,
            movementId: $movementId,
        );
    }

    /**
     * A zero delta is not a correction: it writes a movement that says nothing
     * and, at document level, is refused twice over (`not_in:0` plus the
     * `stock_adjustment_lines_delta_nonzero` CHECK).
     */
    private function assertNonZeroDelta(string $deltaQuantity): void
    {
        /** @var numeric-string $deltaQuantity */
        if (bccomp($deltaQuantity, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('A stock adjustment delta must not be zero.');
        }
    }

    /**
     * The product unit's display precision, carried on every quantity-bearing
     * refusal so the frontend never has to fall back to a literal scale
     * (plan §2 / re-review N-5).
     */
    private function resolveQuantityDecimals(string $productId, string $companyId): int
    {
        $product = Product::query()
            ->with('unitOfMeasure')
            ->where('company_id', $companyId)
            ->find($productId);

        if ($product === null || ! $product->relationLoaded('unitOfMeasure')) {
            return self::SCALE;
        }

        // getRelation() (not the typed accessor) so the null case is visible to
        // PHPStan: a product may carry no unit of measure.
        $unit = $product->getRelation('unitOfMeasure');

        return $unit instanceof Unit ? $unit->decimal_places : self::SCALE;
    }

    /**
     * `product_batches` is int-keyed with a separate public `uuid` column; the
     * HTTP contract speaks uuid, so a refusal must too (D1b part 1).
     */
    private function resolveBatchUuid(?int $batchId): ?string
    {
        if ($batchId === null) {
            return null;
        }

        $uuid = Batch::query()->whereKey($batchId)->value('uuid');

        return $uuid !== null ? (string) $uuid : null;
    }

    /**
     * Apply a finalized count line via timestamp replay, under the SAME lock
     * order as adjust() (ProductCostLock FIRST, then the stock_level row FOR
     * UPDATE). Live-inventory-counting task B3 (spec §4/§5).
     *
     * Replays movements in `(finalQtyAsOf, now]`, computes
     * `expected_now = finalQty + Σ signed_delta` and
     * `adjustment = expected_now − on_hand_now`, and posts it additively as a
     * `count_correction` — or, for the first count of an onboarding line, as an
     * `opening` balance that sets/blends the WAC. Returns the audit DTO when a
     * movement is posted; returns null when the negative-at-apply guard trips
     * (nothing posted, item left for review). The basket-window guard is owned
     * by the listener (it needs no lock and is evaluated before this call).
     *
     * Runs with no CompanyContext (queued finalize listener): tenant/company are
     * resolved from the location/product, and the cost scale used for the WAC
     * write is the constant COST_SCALE (resolver-independent).
     *
     * @param  numeric-string  $finalQty  Counted quantity as of $finalQtyAsOf (scale 4)
     * @param  numeric-string|null  $openingUnitCost  Opening cost at COST_SCALE=6; null leaves WAC untouched
     * @param  StockMovementReferenceType|null  $referenceType  Counting-document morph type;
     *                                                          pass together with $referenceId
     * @param  string|null  $referenceId  Counting-document UUID; pass together with $referenceType
     * @param  \Closure(StockMovement): void|null  $onCountCorrection  GL sink for the
     *                                                                 count_correction movement (T21). Invoked, still inside the lock, ONLY for the
     *                                                                 `postCountCorrection()` branch — `postCountOpening()` writes
     *                                                                 MovementType::Opening / MovementReason::OpeningBalance, which is outside the GL
     *                                                                 seam (inv N-2). Deliberately a closure rather than the Application-layer buffer:
     *                                                                 this is a Domain service, and the hexagonal direction forbids it naming
     *                                                                 InventoryGlPostingBuffer / MovementGlContext. The Application-layer listener owns
     *                                                                 the context construction and the enqueue.
     */
    public function applyCountResult(
        string $productId,
        string $locationId,
        ?string $variantId,
        string $finalQty,
        CarbonInterface $finalQtyAsOf,
        int $ambiguityWindowMinutes,
        bool $onboarding,
        ?string $openingUnitCost,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
        ?\Closure $onCountCorrection = null,
    ): ?ReplayAuditDto {
        $this->assertVariantConsistency($productId, $variantId);
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);
        $scale = InventoryScale::QUANTITY_SCALE;

        return DB::transaction(function () use ($productId, $locationId, $variantId, $finalQty, $finalQtyAsOf, $onboarding, $openingUnitCost, $scale, $referenceType, $referenceId, $onCountCorrection): ?ReplayAuditDto {
            $companyId = $this->resolveCompanyId($locationId);
            $tenantId = $this->resolveTenantId($productId, $companyId);

            // ProductCostLock FIRST (advisory, product-grain), then the
            // stock_level row FOR UPDATE inside the closure. Never invert this —
            // adjust()/recordPurchase/recordSale rely on advisory -> row order.
            return $this->costLock->acquire($tenantId, $companyId, [$productId], function () use ($productId, $locationId, $variantId, $finalQty, $finalQtyAsOf, $onboarding, $openingUnitCost, $companyId, $tenantId, $scale, $referenceType, $referenceId, $onCountCorrection): ?ReplayAuditDto {
                $now = now();
                $stockLevel = $this->lockStockLevel($productId, $locationId, $companyId, $variantId);

                /** @var numeric-string $rawOnHand */
                $rawOnHand = (string) $stockLevel->quantity;
                $onHandNow = bcadd($rawOnHand, '0', $scale);

                // Replay the window (finalQtyAsOf, now] UNDER the row lock so the
                // summed delta and the on-hand read are consistent (a concurrent
                // sale cannot commit while we hold the row lock). The same pure
                // computation powers the review preview.
                $computation = $this->replayService->compute(
                    $productId,
                    $locationId,
                    $variantId,
                    $finalQty,
                    $finalQtyAsOf,
                    $now,
                    $onHandNow,
                );
                $expectedNow = $computation->expectedNow;

                // Guard: negative-at-apply (non-onboarding only). Onboarding
                // deliberately permits negative on-hand (sell-before-count).
                if ($this->countingReplayGuardEvaluator->atApply($onboarding, $expectedNow) !== null) {
                    return null;
                }

                $adjustment = $computation->adjustment;

                $postOpening = $onboarding
                    && $this->firstCountDetector->isFirstCount($productId, $locationId, $variantId);

                if ($postOpening) {
                    $this->postCountOpening($stockLevel, $productId, $locationId, $variantId, $tenantId, $companyId, $onHandNow, $expectedNow, $adjustment, $openingUnitCost, $now, $referenceType, $referenceId);
                } else {
                    $this->postCountCorrection($stockLevel, $productId, $locationId, $variantId, $onHandNow, $expectedNow, $adjustment, $now, $referenceType, $referenceId, $onCountCorrection);
                }

                return new ReplayAuditDto(
                    windowFrom: $finalQtyAsOf->toIso8601String(),
                    windowTo: $now->toIso8601String(),
                    replayedDelta: $computation->movementsSinceCount,
                    onHandAtApply: $onHandNow,
                    expectedAtApply: $expectedNow,
                );
            });
        }, attempts: 3);
    }

    /**
     * Post the replay-computed adjustment as a normal count_correction.
     *
     * T21 — the cost basis. `Product::resolveMovementUnitCost()` (D-3) is
     * resolved ONCE here, BEFORE the movement is created, and written ON it.
     * Both counting paths were NULL-cost before this change (§0t.6), so a GL
     * leg valued at post time would have posted `amount = 0` — a silent
     * zero-value shrinkage. The GL amount is derived from this persisted row
     * (`unit_cost x |quantity_after − quantity_before|`), never from a WAC that
     * may have moved since.
     *
     * @param  numeric-string  $onHandNow
     * @param  numeric-string  $expectedNow
     * @param  numeric-string  $adjustment
     * @param  \Closure(StockMovement): void|null  $onCountCorrection  See applyCountResult().
     */
    private function postCountCorrection(
        StockLevel $stockLevel,
        string $productId,
        string $locationId,
        ?string $variantId,
        string $onHandNow,
        string $expectedNow,
        string $adjustment,
        CarbonInterface $now,
        // REQUIRED (no default): a private helper has no compatibility cost, and
        // a defaulted pair would let a future call site drop the linkage
        // silently — assertReferenceLinkagePaired catches a HALF pair, not a
        // fully omitted one.
        ?StockMovementReferenceType $referenceType,
        ?string $referenceId,
        ?\Closure $onCountCorrection = null,
    ): void {
        // Campaign W4-6 / document-per-action: a zero adjustment justifies
        // nothing. The count AGREES with the shelf, so there is no stock event
        // to record — writing `qty 0.0000, 25 -> 25` produced exactly the rows
        // the campaign found (no-ops for the two agreeing lines while the real
        // variances were suppressed). The replay audit the caller stamps is the
        // evidence that the line WAS evaluated.
        if (bccomp($adjustment, '0', self::SCALE) === 0) {
            return;
        }

        $stockLevel->update(['quantity' => $expectedNow]);

        $movement = $this->recordMovement(
            tenantId: $stockLevel->tenant_id,
            companyId: $stockLevel->company_id,
            productId: $productId,
            locationId: $locationId,
            type: MovementType::Adjustment,
            quantity: $adjustment,
            quantityBefore: $onHandNow,
            quantityAfter: $expectedNow,
            reference: self::COUNT_REPLAY_REFERENCE,
            userId: null,
            variantId: $variantId,
            reason: MovementReason::CountCorrection,
            unitCost: $this->resolveRowUnitCost($productId, $stockLevel->company_id),
            occurredAt: $now,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );

        // Hand the persisted row to the GL sink INSIDE the lock and inside the
        // caller's transaction. Enqueueing is pure (no database work happens
        // until the listener's root flush), so this cannot invert the lock order
        // — see InventoryGlPostingBuffer.
        if ($onCountCorrection !== null) {
            $onCountCorrection($movement);
        }

        if (bccomp($adjustment, '0', self::SCALE) > 0) {
            $this->ensureDefaultBatchForImplicitPositiveStock($stockLevel, $productId);
        } else {
            $this->drawLotsDownForCountShortage($stockLevel, $productId, $adjustment, $movement->id);
        }

        $this->dispatchCountMovementEvents(
            $movement,
            $stockLevel->tenant_id,
            $stockLevel->company_id,
            $productId,
            $locationId,
            MovementType::Adjustment->value,
            $adjustment,
            $expectedNow,
            $variantId,
        );
    }

    /**
     * Move the LOT ledger down with a count SHORTAGE (campaign W4-6 / W2-7).
     *
     * The aggregate `stock_levels` row and the lot ledger have to move together
     * or FEFO becomes fiction: before this arm a count that found two units
     * missing lowered the aggregate and left every lot where it was, so
     * Σ lots > aggregate and the earliest-expiring lot kept promising stock that
     * is not on the shelf. The positive direction already has its counterpart —
     * `ensureDefaultBatchForImplicitPositiveStock()`, which since W2-7 tops the
     * DEFAULT lot up only to the UNTRACKED REMAINDER and therefore never
     * re-mints a phantom lot on top of real ones.
     *
     * FEFO order, EXPIRED LOTS INCLUDED. The count has no lot grain
     * (`inventory_counting_items` is keyed on product/variant/location — W4-8),
     * so soonest-expiry-first is the only defensible attribution available here;
     * and stock that has gone missing off a shelf is more likely the oldest than
     * the newest, so skipping expired lots would push the shortfall onto
     * saleable stock and leave the expired lot claiming units that are gone.
     *
     * TOLERANT, for the same reason `issueFromDefaultBatchByDelta()` is: the
     * aggregate has already moved inside this lock, so a refusal would strand
     * the operator mid-correction. Each lot gives `min(available, remaining)`,
     * which can never overshoot and strictly narrows any gap.
     *
     * Driver-agnostic on purpose — it reads the FEFO order unlocked and lets
     * `issueBatchStock()` take the `inventory_batch_stock` row lock per lot,
     * exactly as the sibling DEFAULT-lot arm does. No new lock is taken on
     * `product_batches`, so the established `stock_levels` →
     * `inventory_batch_stock` order is unchanged.
     *
     * @param  numeric-string  $adjustment  Negative delta already applied to the aggregate
     */
    private function drawLotsDownForCountShortage(
        StockLevel $stockLevel,
        string $productId,
        string $adjustment,
        string $movementId,
    ): void {
        $product = Product::query()
            ->where('company_id', $stockLevel->company_id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            return;
        }

        /** @var numeric-string $remaining */
        $remaining = bcmul($adjustment, '-1', self::SCALE);

        if (bccomp($remaining, '0', self::SCALE) <= 0) {
            return;
        }

        $lots = BatchStock::query()
            ->join('product_batches', 'product_batches.id', '=', 'inventory_batch_stock.batch_id')
            ->where('product_batches.company_id', $stockLevel->company_id)
            ->where('product_batches.product_id', $productId)
            ->where('product_batches.is_recalled', false)
            ->when(
                $stockLevel->variant_id === null,
                static fn ($query) => $query->whereNull('product_batches.variant_id'),
                static fn ($query) => $query->where('product_batches.variant_id', $stockLevel->variant_id),
            )
            ->where('inventory_batch_stock.location_id', $stockLevel->location_id)
            ->orderBy('product_batches.expiry_date')
            ->orderBy('product_batches.created_at')
            ->get([
                'inventory_batch_stock.batch_id',
                'inventory_batch_stock.quantity',
                'inventory_batch_stock.reserved_quantity',
            ]);

        foreach ($lots as $lot) {
            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                break;
            }

            /** @var numeric-string $available */
            $available = bcsub((string) $lot->quantity, (string) $lot->reserved_quantity, self::SCALE);

            if (bccomp($available, '0', self::SCALE) <= 0) {
                continue;
            }

            /** @var numeric-string $take */
            $take = bccomp($available, $remaining, self::SCALE) < 0 ? $available : $remaining;

            $this->batchStockService->issueBatchStock(
                tenantId: $stockLevel->tenant_id,
                batchId: (int) $lot->batch_id,
                locationId: $stockLevel->location_id,
                quantity: $take,
                movementId: $movementId,
            );

            $remaining = bcsub($remaining, $take, self::SCALE);
        }
    }

    /**
     * Post the first count of an onboarding line as an opening balance,
     * additively (movement quantity = adjustment, leaving on-hand = expected).
     * Prior on-hand ≤ 0 SETS the absolute WAC basis; > 0 blends through the
     * existing WAC formula. A null cost posts the movement without a cost and
     * leaves the WAC untouched (the listener flags it pending-cost; the count is
     * NOT blocked).
     *
     * @param  numeric-string  $onHandNow
     * @param  numeric-string  $expectedNow
     * @param  numeric-string  $adjustment
     * @param  numeric-string|null  $openingUnitCost
     */
    private function postCountOpening(
        StockLevel $stockLevel,
        string $productId,
        string $locationId,
        ?string $variantId,
        string $tenantId,
        string $companyId,
        string $onHandNow,
        string $expectedNow,
        string $adjustment,
        ?string $openingUnitCost,
        CarbonInterface $now,
        // REQUIRED (no default) — see postCountCorrection.
        ?StockMovementReferenceType $referenceType,
        ?string $referenceId,
    ): void {
        $stockLevel->update(['quantity' => $expectedNow]);

        $movement = $this->recordMovement(
            tenantId: $stockLevel->tenant_id,
            companyId: $stockLevel->company_id,
            productId: $productId,
            locationId: $locationId,
            type: MovementType::Opening,
            quantity: $adjustment,
            quantityBefore: $onHandNow,
            quantityAfter: $expectedNow,
            reference: self::COUNT_REPLAY_REFERENCE,
            userId: null,
            variantId: $variantId,
            reason: MovementReason::OpeningBalance,
            unitCost: $openingUnitCost,
            occurredAt: $now,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );

        if (bccomp($adjustment, '0', self::SCALE) > 0) {
            $this->ensureDefaultBatchForImplicitPositiveStock($stockLevel, $productId);
        }

        if ($openingUnitCost !== null) {
            $this->applyOpeningWac($productId, $tenantId, $companyId, $onHandNow, $adjustment, $openingUnitCost, $now);
        }

        $this->dispatchCountMovementEvents(
            $movement,
            $stockLevel->tenant_id,
            $stockLevel->company_id,
            $productId,
            $locationId,
            MovementType::Opening->value,
            $adjustment,
            $expectedNow,
            $variantId,
        );
    }

    /**
     * Set or blend the product WAC for an onboarding opening. Locks the product
     * row LAST (canonical order: advisory -> stock_level row -> product row),
     * mirroring WeightedAverageCostService. Costs are carried at COST_SCALE=6.
     *
     * @param  numeric-string  $onHandNow
     * @param  numeric-string  $adjustment
     * @param  numeric-string  $openingUnitCost
     */
    private function applyOpeningWac(
        string $productId,
        string $tenantId,
        string $companyId,
        string $onHandNow,
        string $adjustment,
        string $openingUnitCost,
        CarbonInterface $now,
    ): void {
        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($productId);

        $openingCost = bcadd($openingUnitCost, '0', self::COST_SCALE);

        if (bccomp($onHandNow, '0', self::SCALE) <= 0) {
            // No meaningful prior stock — the opening cost IS the WAC basis.
            $newCost = $openingCost;
        } else {
            // Blend the opened quantity at the opening cost into the running WAC
            // through the existing formula (no new movement — quantity change is
            // already recorded by the opening movement above).
            /** @var numeric-string $currentCost */
            $currentCost = (string) ($product->cost_price ?? '0');
            $newCost = $this->weightedAverageCostService->calculateNewWAC(
                currentQty: $onHandNow,
                currentCost: bcadd($currentCost, '0', self::COST_SCALE),
                newQty: $adjustment,
                newCost: $openingCost,
            );
        }

        $product->cost_price = $newCost;
        $product->cost_updated_at = Carbon::instance($now);
        $product->save();
    }

    /**
     * Dispatch StockMovementRecorded (+ V2 variant-aware) after commit for a
     * replay-based count movement, matching adjust()'s audit stream.
     *
     * @param  numeric-string  $quantity
     * @param  numeric-string  $newStockLevel
     */
    private function dispatchCountMovementEvents(
        StockMovement $movement,
        string $tenantId,
        string $companyId,
        string $productId,
        string $locationId,
        string $movementType,
        string $quantity,
        string $newStockLevel,
        ?string $variantId,
    ): void {
        $movementSnapshot = $movement;

        DB::afterCommit(function () use ($movementSnapshot, $tenantId, $companyId, $productId, $locationId, $movementType, $quantity, $newStockLevel, $variantId): void {
            event(new StockMovementRecorded(
                movementId: $movementSnapshot->id,
                tenantId: $tenantId,
                companyId: $companyId,
                productId: $productId,
                locationId: $locationId,
                movementType: $movementType,
                quantity: $quantity,
                unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                newStockLevel: $newStockLevel,
                reference: self::COUNT_REPLAY_REFERENCE,
                referenceType: $movementSnapshot->reference_type,
                referenceId: $movementSnapshot->reference_id,
                occurredAt: now()->toIso8601String(),
            ));

            event(new StockMovementRecordedV2(
                movementId: $movementSnapshot->id,
                tenantId: $tenantId,
                companyId: $companyId,
                productId: $productId,
                locationId: $locationId,
                movementType: $movementType,
                quantity: $quantity,
                unitCost: (string) ($movementSnapshot->unit_cost ?? '0.00'),
                totalCost: (string) ($movementSnapshot->total_cost ?? '0.00'),
                newStockLevel: $newStockLevel,
                variantId: $variantId,
                reference: self::COUNT_REPLAY_REFERENCE,
                referenceType: $movementSnapshot->reference_type,
                referenceId: $movementSnapshot->reference_id,
                occurredAt: now()->toIso8601String(),
            ));
        });
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

    /**
     * THE per-unit cost an adjustment/count movement is valued at (D-3, T21).
     *
     * One definition — `Product::resolveMovementUnitCost()` — shared by the
     * legacy counting path, the replay counting path and the adjustment
     * document, because a divergence between the persisted `unit_cost` and the
     * GL debit would post an unbalanced correction (gate V10-I5 caught exactly
     * that drift). Resolver-INDEPENDENT: it reads `products.cost_price`, stored
     * at the constant COST_SCALE = 6, and never consults CurrencyScaleResolver,
     * so it is safe in the queued counting listener, which runs with no
     * CompanyContext (house rule 20).
     *
     * The Product lookup is scoped to the company already validated by the
     * locked StockLevel, so a forged productId cannot value a movement off
     * another company's cost.
     *
     * @return numeric-string
     */
    private function resolveRowUnitCost(string $productId, string $companyId): string
    {
        /** @var numeric-string $unitCost */
        $unitCost = Product::query()
            ->where('company_id', $companyId)
            ->findOrFail($productId)
            ->resolveMovementUnitCost();

        return $unitCost;
    }

    private function ensureDefaultBatchForImplicitPositiveStock(StockLevel $stockLevel, string $productId): void
    {
        $product = Product::query()
            ->where('company_id', $stockLevel->company_id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            return;
        }

        // 🚨 Campaign W2-7 — this used to pass the tuple's FULL aggregate quantity
        // as the DEFAULT lot's target, so stock already held by real dated lots was
        // double-booked in the batch ledger. `$stockLevel->quantity` is the
        // AGGREGATE, and the DEFAULT lot may only ever carry the share of it that
        // no real lot accounts for.
        $this->batchStockService->ensureDefaultBatchForUntrackedRemainder(
            companyId: $stockLevel->company_id,
            tenantId: $stockLevel->tenant_id,
            productId: $productId,
            locationId: $stockLevel->location_id,
            aggregateQuantity: (string) $stockLevel->quantity,
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
     * @param  StockMovementReferenceType|null  $referenceType  Source-document morph type,
     *                                                          persisted (as its backing
     *                                                          string) into
     *                                                          `stock_movements.reference_type`.
     * @param  string|null  $referenceId  Source-document UUID persisted into
     *                                    `stock_movements.reference_id`. MUST be supplied
     *                                    together with $referenceType.
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
        ?string $userId = null,
        ?string $variantId = null,
        ?MovementReason $reason = null,
        ?string $unitCost = null,
        ?CarbonInterface $occurredAt = null,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
        ?string $reversesMovementId = null,
    ): StockMovement {
        $this->assertReferenceLinkagePaired($referenceType, $referenceId);

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
            // Document linkage (morph). ADDITIVE to the free-text `reference`
            // label — never a replacement: the label stays human-readable while
            // these two columns carry the machine-resolvable FK.
            'reference_type' => $referenceType?->value,
            'reference_id' => $referenceId,
            // Movement-level reversal linkage (DPA V7 / D8): a contra line points
            // at the movement it corrects. Threaded through the seam rather than
            // set by a post-hoc UPDATE — the shape S0 finding I-5 condemns and
            // that ReverseWriteOffService still ships.
            'reverses_movement_id' => $reversesMovementId,
            'user_id' => $userId,
            // Event time (rule: device time for POS paths, now() otherwise).
            // adjust() threads a device/replay time here; other entry points
            // (receive/issue/transfer) default to now().
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Validate the document-linkage pair before it can reach the row.
     *
     * Two rules, both of them things this seam owes the lanes that adopt it:
     *
     * 1. BOTH halves or NEITHER. A half-specified pair persists a morph no
     *    consumer can resolve and that the `idx_movements_reference` lookup
     *    silently misses.
     * 2. $referenceId MUST be a UUID. `stock_movements.reference_id` is a
     *    PostgreSQL `uuid` column, but the test suite runs on SQLite
     *    (phpunit.xml), which stores any TEXT there happily. Without this check
     *    an adopting lane that links to an int-keyed entity (batches.id, for
     *    one) would be green in CI and 500 in production — inside a queued
     *    listener, where it would burn every retry. This is the house's
     *    documented "UUID cols in PG" trap; catch it at the seam, in the layer
     *    that has the type information.
     */
    private function assertReferenceLinkagePaired(?StockMovementReferenceType $referenceType, ?string $referenceId): void
    {
        if (($referenceType === null) !== ($referenceId === null)) {
            throw new InvalidArgumentException(
                'Stock movement document linkage requires both referenceType and referenceId, or neither.'
            );
        }

        if ($referenceId !== null && ! Str::isUuid($referenceId)) {
            throw new InvalidArgumentException(
                'Stock movement referenceId must be a UUID; got: '.$referenceId
            );
        }
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
