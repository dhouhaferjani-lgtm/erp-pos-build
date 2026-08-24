<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\Contracts\InventoryReservationServiceInterface;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\Events\ReservationCreated;
use App\Modules\Inventory\Domain\Events\ReservationCreatedV2;
use App\Modules\Inventory\Domain\Events\ReservationExpired;
use App\Modules\Inventory\Domain\Events\ReservationExpiredV2;
use App\Modules\Inventory\Domain\Events\ReservationReleased;
use App\Modules\Inventory\Domain\Events\ReservationReleasedV2;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockForFulfilmentException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\Inventory\ReservationReleaserInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * StockReservationService - Manages stock reservations for sales orders, carts, etc.
 *
 * CRITICAL: All methods use database transactions with pessimistic locking
 * to prevent race conditions and ensure stock reservations are atomic.
 *
 * Stock Reservation Lifecycle:
 * 1. Reserve stock when sales order is confirmed
 * 2. Release stock when order is delivered (fulfilled)
 * 3. Release stock when order is cancelled
 * 4. Expire stock when reservation timeout is reached
 *
 * Fraud Detection:
 * - All reservation events are logged for fraud detection
 * - High-value reservations trigger alerts
 * - Suspicious release patterns (expired, manual) are flagged
 */
class StockReservationService implements InventoryReservationServiceInterface, ReservationReleaserInterface
{
    private const int QUANTITY_SCALE = 4;

    public function __construct(
        private FEFOInventoryService $fefoService,
        private BatchStockService $batchStockService,
    ) {}

    /**
     * Reserve stock for a source (sales order, cart, etc.).
     *
     * This method:
     * - Validates sufficient available stock
     * - Creates the reservation record
     * - Updates the stock level reserved field (or batch stock if batch_id provided)
     * - Dispatches ReservationCreated event
     *
     * @param  int|null  $batchId  Optional batch ID for batch-specific reservation
     *
     * @throws InsufficientStockForFulfilmentException If the tuple cannot cover the
     *                                                 request — including when it has
     *                                                 NO `stock_levels` row at all,
     *                                                 which reads as available 0
     *                                                 (campaign N-2). Extends
     *                                                 `\RuntimeException`, so the
     *                                                 pre-existing contract on this
     *                                                 method is unchanged.
     * @throws \RuntimeException If insufficient BATCH stock available
     */
    public function reserve(
        Company $company,
        string $productId,
        string $locationId,
        string $quantity,
        ReservationSource $sourceType,
        string $sourceId,
        ?string $sourceLineId = null,
        ?int $priority = 0,
        ?string $notes = null,
        ?int $batchId = null,
    ): StockReservation {
        return DB::transaction(function () use (
            $company,
            $productId,
            $locationId,
            $quantity,
            $sourceType,
            $sourceId,
            $sourceLineId,
            $priority,
            $notes,
            $batchId
        ): StockReservation {
            $batchId = $batchId ?? $this->resolveDefaultBatchIdForImplicitReservation(
                company: $company,
                productId: $productId,
                locationId: $locationId,
            );

            // If batch_id provided, validate batch stock instead of aggregate stock
            /** @var numeric-string $quantity */
            if ($batchId !== null) {
                // Lock batch stock to prevent concurrent reservations
                $batchStock = BatchStock::where('batch_id', $batchId)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Validate sufficient available stock in this batch
                $available = bcsub((string) $batchStock->quantity, (string) $batchStock->reserved_quantity, 4);
                if (bccomp($available, $quantity, 4) < 0) {
                    throw new \RuntimeException(
                        "Insufficient batch stock. Available: {$available}, Requested: {$quantity}"
                    );
                }
            } else {
                // Lock aggregate stock level to prevent concurrent reservations.
                //
                // 🚨 Campaign N-2: `first()`, NOT `firstOrFail()`. Every day-one
                // product has no `stock_levels` row, and `firstOrFail()` turned
                // that into a `ModelNotFoundException` — a raw 404 out of
                // `SalesOrderController::confirm()`, whose only catch is
                // `\DomainException`. An absent row is not a missing resource:
                // the product and the location both exist and the tuple simply
                // holds nothing, so it is read as available 0 and refused by the
                // SAME predicate an existing row at quantity 0 already fails.
                // Deliberately NOT `firstOrCreate`: a refused reservation must
                // not write a phantom row (and the row it would create would be
                // refused on the very next line anyway).
                $stockLevel = StockLevel::where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->first();

                // Validate sufficient available stock
                $available = $stockLevel === null
                    ? '0.0000'
                    : bcsub((string) $stockLevel->quantity, (string) $stockLevel->reserved, 4);

                if ($stockLevel === null || bccomp($available, $quantity, 4) < 0) {
                    throw $this->insufficientStock(
                        company: $company,
                        productId: $productId,
                        locationId: $locationId,
                        available: $available,
                        requested: $quantity,
                    );
                }
            }

            // Get reservation settings from company
            $settings = $company->getReservationSettings();

            // Calculate expiry based on source type and settings
            $expiresAt = $sourceType->getDefaultExpiry($settings);

            // Create reservation record
            $reservation = StockReservation::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'product_id' => $productId,
                'location_id' => $locationId,
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceLineId,
                'expires_at' => $expiresAt,
                'priority' => $priority ?? 0,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            // Update reserved quantities
            if ($batchId !== null) {
                // Update batch stock reserved quantity
                $batchStock->update([
                    'reserved_quantity' => bcadd((string) $batchStock->reserved_quantity, $quantity, self::QUANTITY_SCALE),
                ]);
            } else {
                // Update aggregate stock level reserved field
                $stockLevel->update([
                    'reserved' => bcadd((string) $stockLevel->reserved, $quantity, self::QUANTITY_SCALE),
                ]);
            }

            // Dispatch event after transaction commits
            DB::afterCommit(function () use ($reservation, $company): void {
                event(new ReservationCreated(
                    reservationId: (string) $reservation->id,
                    companyId: $company->id,
                    productId: $reservation->product_id,
                    locationId: $reservation->location_id,
                    quantity: (string) $reservation->quantity,
                    sourceType: $reservation->source_type->value,
                    sourceId: $reservation->source_id,
                    sourceLineId: $reservation->source_line_id,
                    expiresAt: $reservation->expires_at?->toIso8601String(),
                    priority: $reservation->priority,
                    createdBy: (string) $reservation->created_by,
                    createdAt: $reservation->created_at?->toIso8601String() ?? now()->toIso8601String(),
                ));

                // V2 dual-dispatch (variant-aware). variant_id is read from the
                // reservation row (null until reserve() is wired to set it).
                event(new ReservationCreatedV2(
                    reservationId: (string) $reservation->id,
                    companyId: $company->id,
                    productId: $reservation->product_id,
                    locationId: $reservation->location_id,
                    quantity: (string) $reservation->quantity,
                    sourceType: $reservation->source_type->value,
                    sourceId: $reservation->source_id,
                    sourceLineId: $reservation->source_line_id,
                    expiresAt: $reservation->expires_at?->toIso8601String(),
                    priority: $reservation->priority,
                    createdBy: (string) $reservation->created_by,
                    createdAt: $reservation->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    variantId: $reservation->variant_id,
                ));
            });

            return $reservation;
        });
    }

    private function resolveDefaultBatchIdForImplicitReservation(
        Company $company,
        string $productId,
        string $locationId,
    ): ?int {
        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            return null;
        }

        // 🚨 Campaign N-2: `first()`, NOT `firstOrFail()`. This runs BEFORE the
        // aggregate branch's own lookup, so on a batch-tracked day-one product it
        // was the FIRST `firstOrFail()` to fire and produced the same raw 404.
        // With no stock row there is no quantity to seed a default batch from, so
        // there is no implicit batch to resolve: return null and let the
        // aggregate branch raise the honest INSUFFICIENT_STOCK refusal.
        $stockLevel = StockLevel::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $company->id)
            ->whereNull('variant_id')
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            return null;
        }

        $batch = $this->batchStockService->ensureDefaultBatch(
            companyId: $company->id,
            tenantId: $company->tenant_id,
            productId: $productId,
            locationId: $locationId,
            targetQuantity: (string) $stockLevel->quantity,
            shelfLifeDays: $product->default_shelf_life_days,
            asOfDate: now()->toDateString(),
            variantId: $stockLevel->variant_id,
        );

        return $batch?->id;
    }

    /**
     * Build the typed refusal for "this tuple cannot cover the request".
     *
     * The product and location names are resolved HERE, on the cold path only, so
     * the operator reads "Insufficient stock for 'Brake pad' at 'Main Warehouse'"
     * instead of two UUIDs, and the happy path pays nothing for it.
     *
     * @param  numeric-string  $available
     * @param  numeric-string  $requested
     */
    private function insufficientStock(
        Company $company,
        string $productId,
        string $locationId,
        string $available,
        string $requested,
    ): InsufficientStockForFulfilmentException {
        $product = Product::query()
            ->with('unitOfMeasure')
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereKey($productId)
            ->first();

        $locationName = Location::query()
            ->where('company_id', $company->id)
            ->whereKey($locationId)
            ->value('name');

        // Gate r1 M-3: quantities reach the operator at the product UNIT's own
        // precision (rule 19, display leg), not at the storage scale of 4.
        $unit = $product?->unitOfMeasure;

        return new InsufficientStockForFulfilmentException(
            productId: $productId,
            productName: $product?->name,
            locationId: $locationId,
            locationName: is_string($locationName) ? $locationName : null,
            available: $available,
            requested: $requested,
            quantityDecimals: $unit?->decimal_places,
            roundingMethod: $unit?->rounding_method->value,
        );
    }

    /**
     * Release a specific reservation.
     *
     * This method:
     * - Marks the reservation as released
     * - Updates the stock level reserved field (or batch stock if batch-specific)
     * - Dispatches ReservationReleased event
     */
    public function release(
        StockReservation $reservation,
        ReleaseReason $reason,
        ?string $releasedBy = null,
    ): void {
        if (! $reservation->isActive()) {
            return; // Already released
        }

        DB::transaction(function () use ($reservation, $reason, $releasedBy): void {
            // Update reservation
            $reservation->update([
                'released_at' => now(),
                'released_by' => $releasedBy ?? auth()->id(),
                'release_reason' => $reason,
            ]);

            // Update reserved quantities
            if ($reservation->batch_id !== null) {
                // Lock and update batch stock
                $batchStock = BatchStock::where('batch_id', $reservation->batch_id)
                    ->where('location_id', $reservation->location_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $batchStock->decrement('reserved_quantity', (float) $reservation->quantity);
            } else {
                // Lock and update aggregate stock level
                $stockLevel = StockLevel::where('product_id', $reservation->product_id)
                    ->where('location_id', $reservation->location_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $stockLevel->decrement('reserved', (float) $reservation->quantity);
            }

            // Dispatch event after transaction commits
            DB::afterCommit(function () use ($reservation, $reason, $releasedBy): void {
                event(new ReservationReleased(
                    reservationId: (string) $reservation->id,
                    companyId: $reservation->company_id,
                    productId: $reservation->product_id,
                    locationId: $reservation->location_id,
                    quantity: (string) $reservation->quantity,
                    sourceType: $reservation->source_type->value,
                    sourceId: $reservation->source_id,
                    releaseReason: $reason->value,
                    releasedBy: $releasedBy ?? (string) auth()->id(),
                    releasedAt: $reservation->released_at?->toIso8601String() ?? now()->toIso8601String(),
                ));

                // V2 dual-dispatch (variant-aware).
                event(new ReservationReleasedV2(
                    reservationId: (string) $reservation->id,
                    companyId: $reservation->company_id,
                    productId: $reservation->product_id,
                    locationId: $reservation->location_id,
                    quantity: (string) $reservation->quantity,
                    sourceType: $reservation->source_type->value,
                    sourceId: $reservation->source_id,
                    releaseReason: $reason->value,
                    releasedBy: $releasedBy ?? (string) auth()->id(),
                    releasedAt: $reservation->released_at?->toIso8601String() ?? now()->toIso8601String(),
                    variantId: $reservation->variant_id,
                ));
            });
        });
    }

    /**
     * Release all active reservations for a source (e.g., sales order).
     *
     * Accepts optional caller-supplied $expectedTenantId and $expectedCompanyId
     * so the query is scoped to the caller's company. When provided, only
     * reservations whose company_id matches $expectedCompanyId are released,
     * preventing a forged cross-company sourceId from releasing foreign
     * reservations (api.inventory.033).
     *
     * This is called when a sales order is cancelled or delivered.
     *
     * Note: $expectedTenantId is accepted for API symmetry with other tenant-
     * scoped service methods, but is NOT used in the WHERE clause —
     * stock_reservations has no tenant_id column. company_id is sufficient
     * because each company belongs to exactly one tenant. If a future
     * migration adds tenant_id to stock_reservations, wire $expectedTenantId
     * into the predicate here. See Codex review M1 (2026-05-09).
     */
    public function releaseBySource(
        ReservationSource $sourceType,
        string $sourceId,
        ReleaseReason $reason,
        ?string $releasedBy = null,
        ?string $expectedTenantId = null,
        ?string $expectedCompanyId = null,
    ): int {
        $query = StockReservation::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->active();

        if ($expectedCompanyId !== null) {
            $query->where('company_id', $expectedCompanyId);
        }

        $reservations = $query->get();

        $count = 0;
        foreach ($reservations as $reservation) {
            $this->release($reservation, $reason, $releasedBy);
            $count++;
        }

        return $count;
    }

    /**
     * Mark expired reservations.
     *
     * This method:
     * - Finds all active reservations that have expired
     * - Marks them as released with ReleaseReason::Expired
     * - Dispatches ReservationExpired events
     *
     * Called by the scheduled `inventory:expire-reservations` command
     * (App\Modules\Inventory\Infrastructure\Commands\ExpireStockReservationsCommand),
     * once per tenant inside that tenant's database connection. Replaced the
     * central-context ExpireReservationsJob queue job on 2026-08-04.
     */
    public function expireReservations(): int
    {
        $expiredReservations = StockReservation::expired()->get();

        $count = 0;
        foreach ($expiredReservations as $reservation) {
            DB::transaction(function () use ($reservation): void {
                // Update reservation
                $reservation->update([
                    'expired_at' => now(),
                    'released_at' => now(),
                    'release_reason' => ReleaseReason::Expired,
                ]);

                // Update reserved quantities
                if ($reservation->batch_id !== null) {
                    // Lock and update batch stock
                    $batchStock = BatchStock::where('batch_id', $reservation->batch_id)
                        ->where('location_id', $reservation->location_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $batchStock->decrement('reserved_quantity', (float) $reservation->quantity);
                } else {
                    // Lock and update aggregate stock level
                    $stockLevel = StockLevel::where('product_id', $reservation->product_id)
                        ->where('location_id', $reservation->location_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $stockLevel->decrement('reserved', (float) $reservation->quantity);
                }

                // Dispatch event after transaction commits
                DB::afterCommit(function () use ($reservation): void {
                    event(new ReservationExpired(
                        reservationId: (string) $reservation->id,
                        companyId: $reservation->company_id,
                        productId: $reservation->product_id,
                        locationId: $reservation->location_id,
                        quantity: (string) $reservation->quantity,
                        sourceType: $reservation->source_type->value,
                        sourceId: $reservation->source_id,
                        originalExpiresAt: $reservation->expires_at?->toIso8601String() ?? '',
                        expiredAt: $reservation->expired_at?->toIso8601String() ?? now()->toIso8601String(),
                    ));

                    // V2 dual-dispatch (variant-aware).
                    event(new ReservationExpiredV2(
                        reservationId: (string) $reservation->id,
                        companyId: $reservation->company_id,
                        productId: $reservation->product_id,
                        locationId: $reservation->location_id,
                        quantity: (string) $reservation->quantity,
                        sourceType: $reservation->source_type->value,
                        sourceId: $reservation->source_id,
                        originalExpiresAt: $reservation->expires_at?->toIso8601String() ?? '',
                        expiredAt: $reservation->expired_at?->toIso8601String() ?? now()->toIso8601String(),
                        variantId: $reservation->variant_id,
                    ));
                });
            });
            $count++;
        }

        return $count;
    }

    /**
     * Recalculate reserved quantities for all stock levels.
     *
     * Useful for fixing drift between reserved field and actual reservations.
     * This should be run as a maintenance task, not during normal operations.
     */
    public function recalculateAllReserved(Company $company): int
    {
        $stockLevels = StockLevel::where('company_id', $company->id)
            ->where('reserved', '>', 0)
            ->get();

        $count = 0;
        foreach ($stockLevels as $stockLevel) {
            $stockLevel->recalculateReserved();
            $count++;
        }

        return $count;
    }

    /**
     * Reserve stock using FEFO (First-Expired-First-Out) batch selection.
     *
     * This method:
     * - Checks if product requires batch tracking
     * - Uses FEFO service to select batches
     * - Creates one reservation per batch
     * - Updates batch stock reserved quantities
     *
     * @param  numeric-string  $quantity  Quantity to reserve (decimal string, 4dp).
     *                                    Passed straight through to the FEFO service —
     *                                    never cast to float (precision contract).
     * @return Collection<int, StockReservation> Collection of created reservations
     *
     * @throws \RuntimeException If insufficient stock available
     */
    public function reserveWithFEFO(
        Company $company,
        string $productId,
        string $locationId,
        string $quantity,
        ReservationSource $sourceType,
        string $sourceId,
        ?string $sourceLineId = null,
        ?int $priority = 0,
        ?string $notes = null,
    ): Collection {
        // Check if product requires batch tracking — scoped to caller's
        // tenant + company so a foreign productId can never satisfy the
        // lookup, even if upstream validators were bypassed.
        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($productId);

        if (! $product->requires_batch_tracking) {
            // Product doesn't require batch tracking, create single aggregate reservation
            return collect([
                $this->reserve(
                    company: $company,
                    productId: $productId,
                    locationId: $locationId,
                    quantity: $quantity,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    sourceLineId: $sourceLineId,
                    priority: $priority,
                    notes: $notes,
                ),
            ]);
        }

        // Use FEFO to select batches
        $result = $this->fefoService->suggestBatchesForSale(
            productId: $product->id,
            locationId: $locationId,
            quantity: $quantity,
        );

        if (! $result->fullyFulfilled) {
            throw new \RuntimeException(
                "Insufficient batch stock. Requested: {$quantity}, Shortfall: {$result->shortfall}"
            );
        }

        // Create one reservation per batch
        $reservations = collect();
        foreach ($result->suggestions as $suggestion) {
            $reservation = $this->reserve(
                company: $company,
                productId: $productId,
                locationId: $locationId,
                quantity: (string) $suggestion->quantity,
                sourceType: $sourceType,
                sourceId: $sourceId,
                sourceLineId: $sourceLineId,
                priority: $priority,
                notes: $notes,
                batchId: $suggestion->batch->id,
            );

            $reservations->push($reservation);
        }

        return $reservations;
    }

    /**
     * Reserve stock for a specific WorkOrder line.
     *
     * Resolves company/location from the best StockLevel row for the product
     * (highest available quantity) and delegates to the standard reserve() path
     * with ReservationSource::WorkOrder.
     *
     * @param  numeric-string  $quantity
     *
     * @throws \RuntimeException If no StockLevel exists for the product.
     */
    public function reserveForWorkOrder(
        string $tenantId,
        string $companyId,
        string $productId,
        string $quantity,
        string $workOrderLineId,
        string $workOrderId,
        ?\DateTimeImmutable $expiresAt,
    ): StockReservation {
        // Scope the StockLevel lookup to the caller's tenant + company.
        // Prior to api.inventory Codex round-2 Finding 1, this method
        // derived Company from `StockLevel::where('product_id',$productId)
        // ->first()->company_id`, allowing a forged cross-company productId
        // to anchor a reservation against a foreign company's stock on
        // Workshop approval. Now an unauthorized cross-company productId
        // returns no row → RuntimeException → reservation is rejected.
        /** @var StockLevel|null $stockLevel */
        $stockLevel = StockLevel::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->orderByRaw('(quantity - reserved) DESC')
            ->first();

        if ($stockLevel === null) {
            throw new \RuntimeException(
                "Cannot reserve stock for product {$productId}: no StockLevel row found."
            );
        }

        /** @var Company $company */
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($companyId);

        $reservation = $this->reserve(
            company: $company,
            productId: $productId,
            locationId: $stockLevel->location_id,
            quantity: $quantity,
            sourceType: ReservationSource::WorkOrder,
            sourceId: $workOrderId,
            sourceLineId: $workOrderLineId,
            priority: 0,
            notes: null,
        );

        if ($expiresAt !== null) {
            $reservation->forceFill(['expires_at' => $expiresAt])->save();
        }

        return $reservation;
    }

    /**
     * Release all active reservations tied to a WorkOrder.
     *
     * @return int Number of reservations released.
     */
    public function releaseForWorkOrder(
        string $workOrderId,
        string $reasonCode,
        ?string $expectedTenantId = null,
        ?string $expectedCompanyId = null,
    ): int {
        $reason = ReleaseReason::tryFrom($reasonCode) ?? ReleaseReason::ManualRelease;

        return $this->releaseBySource(
            sourceType: ReservationSource::WorkOrder,
            sourceId: $workOrderId,
            reason: $reason,
            expectedTenantId: $expectedTenantId,
            expectedCompanyId: $expectedCompanyId,
        );
    }

    public function releaseReservationsForSource(
        string $sourceType,
        string $sourceId,
        string $reason,
        ?string $releasedBy = null,
        ?string $expectedTenantId = null,
        ?string $expectedCompanyId = null,
    ): int {
        return $this->releaseBySource(
            ReservationSource::from($sourceType),
            $sourceId,
            ReleaseReason::from($reason),
            $releasedBy,
            $expectedTenantId,
            $expectedCompanyId,
        );
    }
}
