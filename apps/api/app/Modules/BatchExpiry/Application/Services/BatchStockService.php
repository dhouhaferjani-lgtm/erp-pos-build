<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\Services;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\Exceptions\MissingVariantException;
use Illuminate\Support\Facades\DB;

/**
 * Consolidates batch-level stock operations used across goods receipt,
 * delivery note confirmation, POS sales, and transfers.
 *
 * IMPORTANT: This service manages ONLY batch-level stock (BatchStock + BatchMovement).
 * Aggregate stock levels are managed by WAC/StockAdjustmentService in the calling code.
 */
final class BatchStockService
{
    public function __construct(
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly ProductVariantLookup $variantLookup,
    ) {}

    /**
     * Find an existing batch or create a new one (for goods receipt).
     *
     * @param  ?string  $variantId  When set, the batch is scoped to this variant.
     *                              When null and the product has active variants,
     *                              throws MissingVariantException — a variant-bearing
     *                              product must never receive a product-level batch.
     *
     * @throws MissingVariantException when variantId is null and the product has active variants.
     */
    public function findOrCreateBatch(
        string $companyId,
        string $tenantId,
        string $productId,
        string $batchNumber,
        string $expiryDate,
        ?string $manufacturingDate = null,
        ?string $variantId = null,
    ): Batch {
        // Guard: reject product-level batch for variant-bearing products.
        if ($variantId === null) {
            $activeVariants = $this->variantLookup->listForProduct($productId, true);
            if ($activeVariants->isNotEmpty()) {
                throw MissingVariantException::withId($productId);
            }
        }

        // Use the variant-aware find so the same batch_number can coexist across
        // the product-level and per-variant partial index partitions (Task 7).
        $existing = $this->batchRepository->findByBatchNumberAndVariant(
            $companyId,
            $productId,
            $batchNumber,
            $variantId,
        );

        if ($existing !== null) {
            return $existing;
        }

        $data = [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'manufacturing_date' => $manufacturingDate,
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ];

        if ($variantId !== null) {
            $data['variant_id'] = $variantId;
        }

        return $this->batchRepository->create($data);
    }

    /**
     * Receive stock into a batch at a location.
     *
     * Creates a BatchMovement record and updates (or creates) the BatchStock level.
     * Does NOT touch aggregate stock levels — that's handled by WAC service.
     *
     * @param  numeric-string  $quantity  Positive quantity to receive
     */
    public function receiveBatchStock(
        string $tenantId,
        int $batchId,
        string $locationId,
        string $quantity,
        ?string $movementId = null,
    ): void {
        DB::transaction(function () use ($tenantId, $batchId, $locationId, $quantity, $movementId): void {
            $this->recordBatchMovement(
                tenantId: $tenantId,
                batchId: $batchId,
                locationId: $locationId,
                quantity: $quantity,
                movementId: $movementId,
            );
        });
    }

    /**
     * Issue stock from a batch at a location.
     *
     * Creates a BatchMovement record (negative qty) and decrements BatchStock.
     * Does NOT touch aggregate stock levels — that's handled by WAC service.
     *
     * @param  numeric-string  $quantity  Positive quantity to issue (will be negated internally)
     *
     * @throws \DomainException If insufficient batch stock
     */
    public function issueBatchStock(
        string $tenantId,
        int $batchId,
        string $locationId,
        string $quantity,
        ?string $movementId = null,
    ): void {
        DB::transaction(function () use ($tenantId, $batchId, $locationId, $quantity, $movementId): void {
            // Lock batch stock for update
            $batchStock = BatchStock::where('batch_id', $batchId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if ($batchStock === null || bccomp((string) $batchStock->available_quantity, $quantity, 4) < 0) {
                $available = $batchStock !== null ? (string) $batchStock->available_quantity : '0.0000';
                throw new \DomainException(
                    "Insufficient batch stock. Batch ID: {$batchId}, Available: {$available}, Requested: {$quantity}"
                );
            }

            $this->recordBatchMovement(
                tenantId: $tenantId,
                batchId: $batchId,
                locationId: $locationId,
                quantity: bcmul($quantity, '-1', 4),
                movementId: $movementId,
            );
        });
    }

    /**
     * Transfer batch stock between locations.
     *
     * @param  numeric-string  $quantity  Positive quantity to transfer
     *
     * @throws \DomainException If insufficient batch stock at source
     */
    public function transferBatchStock(
        string $tenantId,
        int $batchId,
        string $fromLocationId,
        string $toLocationId,
        string $quantity,
        string $reference,
        string $userId,
    ): void {
        DB::transaction(function () use ($tenantId, $batchId, $fromLocationId, $toLocationId, $quantity): void {
            // Lock source batch stock
            $sourceBatchStock = BatchStock::where('batch_id', $batchId)
                ->where('location_id', $fromLocationId)
                ->lockForUpdate()
                ->first();

            if ($sourceBatchStock === null || bccomp((string) $sourceBatchStock->available_quantity, $quantity, 4) < 0) {
                $available = $sourceBatchStock !== null ? (string) $sourceBatchStock->available_quantity : '0.0000';
                throw new \DomainException(
                    "Insufficient batch stock for transfer. Batch ID: {$batchId}, Available: {$available}, Requested: {$quantity}"
                );
            }

            // Deduct from source
            $this->recordBatchMovement(
                tenantId: $tenantId,
                batchId: $batchId,
                locationId: $fromLocationId,
                quantity: bcmul($quantity, '-1', 4),
            );

            // Add to destination
            $this->recordBatchMovement(
                tenantId: $tenantId,
                batchId: $batchId,
                locationId: $toLocationId,
                quantity: $quantity,
            );
        });
    }

    /**
     * Record a batch movement and update batch stock level.
     *
     * @param  numeric-string  $quantity  Positive for receipt, negative for issue
     */
    private function recordBatchMovement(
        string $tenantId,
        int $batchId,
        string $locationId,
        string $quantity,
        ?string $movementId = null,
    ): void {
        // Create batch movement record
        $data = [
            'tenant_id' => $tenantId,
            'batch_id' => $batchId,
            'quantity' => $quantity,
        ];

        if ($movementId !== null) {
            $data['movement_id'] = $movementId;
        }

        BatchMovement::create($data);

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
        $currentQuantity = (string) $batchStock->quantity;
        $newQuantity = bcadd($currentQuantity, $quantity, 4);

        $batchStock->update(['quantity' => $newQuantity]);
    }
}
