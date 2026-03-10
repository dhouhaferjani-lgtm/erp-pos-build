<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

final class StockAdjustmentService
{
    private const SCALE = 2;

    /**
     * Receive stock into a location (e.g., from purchase order).
     *
     * @param  numeric-string  $quantity
     * @param  int|null  $batchId  Optional batch ID for batch-tracked products
     */
    public function receive(
        string $productId,
        string $locationId,
        string $quantity,
        string $reference,
        string $userId,
        ?int $batchId = null,
    ): StockMovement {
        return DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId): StockMovement {
            $stockLevel = $this->getOrCreateStockLevel($productId, $locationId);

            /** @var numeric-string $quantityBefore */
            $quantityBefore = $stockLevel->quantity;
            $quantityAfter = bcadd($quantityBefore, $quantity, self::SCALE);

            $stockLevel->update(['quantity' => $quantityAfter]);

            $movement = $this->recordMovement(
                tenantId: $stockLevel->tenant_id,
                productId: $productId,
                locationId: $locationId,
                type: MovementType::Receipt,
                quantity: $quantity,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                reference: $reference,
                userId: $userId,
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
            }

            return $movement;
        });
    }

    /**
     * Issue stock from a location (e.g., for sales order).
     *
     * @param  numeric-string  $quantity
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
    ): StockMovement {
        return DB::transaction(function () use ($productId, $locationId, $quantity, $reference, $userId, $batchId): StockMovement {
            $stockLevel = $this->lockStockLevel($productId, $locationId);

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
                productId: $productId,
                locationId: $locationId,
                type: MovementType::Issue,
                quantity: $quantity,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                reference: $reference,
                userId: $userId,
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
    ): void {
        DB::transaction(function () use ($productId, $fromLocationId, $toLocationId, $quantity, $reference, $userId): void {
            // Lock source stock
            $sourceStock = $this->lockStockLevel($productId, $fromLocationId);

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

            $this->recordMovement(
                tenantId: $sourceStock->tenant_id,
                productId: $productId,
                locationId: $fromLocationId,
                type: MovementType::TransferOut,
                quantity: $quantity,
                quantityBefore: $sourceQuantityBefore,
                quantityAfter: $sourceQuantityAfter,
                reference: $reference,
                userId: $userId,
            );

            // Add to destination
            $destStock = $this->getOrCreateStockLevel($productId, $toLocationId);
            /** @var numeric-string $destQuantityBefore */
            $destQuantityBefore = $destStock->quantity;
            $destQuantityAfter = bcadd($destQuantityBefore, $quantity, self::SCALE);
            $destStock->update(['quantity' => $destQuantityAfter]);

            $this->recordMovement(
                tenantId: $destStock->tenant_id,
                productId: $productId,
                locationId: $toLocationId,
                type: MovementType::TransferIn,
                quantity: $quantity,
                quantityBefore: $destQuantityBefore,
                quantityAfter: $destQuantityAfter,
                reference: $reference,
                userId: $userId,
            );
        });
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
    ): void {
        DB::transaction(function () use ($productId, $locationId, $quantity): void {
            $stockLevel = $this->lockStockLevel($productId, $locationId);

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
    ): void {
        DB::transaction(function () use ($productId, $locationId, $quantity): void {
            $stockLevel = $this->lockStockLevel($productId, $locationId);

            /** @var numeric-string $reserved */
            $reserved = $stockLevel->reserved;
            $newReserved = bcsub($reserved, $quantity, self::SCALE);
            if (bccomp($newReserved, '0.00', self::SCALE) < 0) {
                $newReserved = '0.00';
            }

            $stockLevel->update(['reserved' => $newReserved]);
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
    ): StockMovement {
        return DB::transaction(function () use ($productId, $locationId, $newQuantity, $reason, $userId): StockMovement {
            $stockLevel = $this->getOrCreateStockLevel($productId, $locationId);

            /** @var numeric-string $quantityBefore */
            $quantityBefore = $stockLevel->quantity;
            $difference = bcsub($newQuantity, $quantityBefore, self::SCALE);

            $stockLevel->update(['quantity' => $newQuantity]);

            return $this->recordMovement(
                tenantId: $stockLevel->tenant_id,
                productId: $productId,
                locationId: $locationId,
                type: MovementType::Adjustment,
                quantity: $difference,
                quantityBefore: $quantityBefore,
                quantityAfter: $newQuantity,
                reference: $reason,
                userId: $userId,
            );
        });
    }

    /**
     * Get or create a stock level record for a product at a location.
     */
    private function getOrCreateStockLevel(string $productId, string $locationId): StockLevel
    {
        $product = Product::findOrFail($productId);
        $location = Location::findOrFail($locationId);

        return StockLevel::firstOrCreate(
            [
                'product_id' => $productId,
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
    private function lockStockLevel(string $productId, string $locationId): StockLevel
    {
        $stockLevel = StockLevel::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            // Create with zero quantity if doesn't exist
            $stockLevel = $this->getOrCreateStockLevel($productId, $locationId);

            // Re-lock
            return StockLevel::query()
                ->where('id', $stockLevel->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $stockLevel;
    }

    /**
     * Record a stock movement.
     */
    private function recordMovement(
        string $tenantId,
        string $productId,
        string $locationId,
        MovementType $type,
        string $quantity,
        string $quantityBefore,
        string $quantityAfter,
        string $reference,
        string $userId,
    ): StockMovement {
        $location = Location::findOrFail($locationId);

        return StockMovement::create([
            'tenant_id' => $tenantId,
            'company_id' => $location->company_id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference' => $reference,
            'user_id' => $userId,
        ]);
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
