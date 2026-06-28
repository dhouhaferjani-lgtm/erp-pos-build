<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\Services;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\Exceptions\MissingVariantException;
use Carbon\CarbonImmutable;
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
    /**
     * Batch number used for the auto-created lot that backs a batch-tracked
     * product's opening / seeded stock when no explicit lot was supplied.
     */
    public const DEFAULT_BATCH_NUMBER = 'DEFAULT';

    /**
     * Fallback shelf life (days) when a batch-tracked product has no
     * `default_shelf_life_days` configured. Conservative 1-year default.
     */
    public const DEFAULT_SHELF_LIFE_DAYS = 365;

    public function __construct(
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly ProductVariantLookup $variantLookup,
    ) {}

    /**
     * Guarantee a batch-tracked product's stock is backed by a lot
     * (the default-batch invariant).
     *
     * Mints — or reuses — a single {@see self::DEFAULT_BATCH_NUMBER} lot for the
     * product (+ variant) with expiry = asOfDate + shelf life, then reconciles
     * the location's batch stock UP to $targetQuantity. Idempotent: re-calling
     * with the same target is a no-op; calling with a larger target tops up the
     * difference (multi-location / incremental seeding safe). Never reduces
     * stock. Returns null (mints nothing) for a non-positive target.
     *
     * Used by the opening-balance posting path (production) and the demo
     * seeders so seeded data mirrors real default behavior.
     *
     * @param  numeric-string  $targetQuantity  Desired batch-stock quantity at the location
     * @param  ?int  $shelfLifeDays  Product default expiry period; null falls back to {@see self::DEFAULT_SHELF_LIFE_DAYS}
     * @param  string  $asOfDate  Opening/seed date (Y-m-d); expiry is measured from here
     */
    public function ensureDefaultBatch(
        string $companyId,
        string $tenantId,
        string $productId,
        string $locationId,
        string $targetQuantity,
        ?int $shelfLifeDays,
        string $asOfDate,
        ?string $variantId = null,
    ): ?Batch {
        if (bccomp($targetQuantity, '0', 4) <= 0) { // precision-ok: batch quantity is decimal(15,4), canonical scale 4
            return null;
        }

        $days = $shelfLifeDays ?? self::DEFAULT_SHELF_LIFE_DAYS;
        $expiryDate = CarbonImmutable::parse($asOfDate)->addDays($days)->toDateString();

        $batch = $this->findOrCreateBatch(
            companyId: $companyId,
            tenantId: $tenantId,
            productId: $productId,
            batchNumber: self::DEFAULT_BATCH_NUMBER,
            expiryDate: $expiryDate,
            manufacturingDate: $asOfDate,
            variantId: $variantId,
        );

        // Reconcile the location's batch stock DIRECTLY (no BatchMovement):
        // inventory_batch_movements.movement_id is a NOT-NULL FK to
        // stock_movements, but a default/seeded backfill has no movement — and
        // the seeder already creates aggregate StockLevel rows movement-free.
        // Batch totals sum BatchStock (not the movement ledger), so this keeps
        // lot totals correct without inventing a movement.
        $batchStock = BatchStock::firstOrCreate(
            ['batch_id' => $batch->id, 'location_id' => $locationId],
            ['tenant_id' => $tenantId, 'quantity' => '0.0000', 'reserved_quantity' => '0.0000'],
        );

        /** @var numeric-string $current */
        $current = (string) $batchStock->quantity;
        $delta = bcsub($targetQuantity, $current, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4

        if (bccomp($delta, '0', 4) > 0) { // precision-ok: batch quantity is decimal(15,4), canonical scale 4
            $batchStock->update(['quantity' => bcadd($current, $delta, 4)]); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
        }

        return $batch;
    }

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
                throw MissingVariantException::forProduct($productId);
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
     * Issue stock from a SPECIFIC batch at a location (strict fulfillment).
     *
     * This is the batch-targeted issue path: the caller already knows which batch
     * to draw down — a write-off targeting one (possibly expired/recalled) batch,
     * or a delivery-note line with a pre-assigned batch_id. It is NOT the FEFO
     * product-level consume; that is {@see FEFOInventoryService::consumeBatchesAtomically()},
     * which POS sales use and which deliberately excludes expired/recalled batches.
     *
     * Atomic + strict: the batch_stock row is locked FOR UPDATE inside a
     * transaction, and an insufficient balance throws InsufficientBatchStockException
     * (carrying the shortfall) — rolling back the whole pass. This is the strict
     * single-batch analogue of the atomic FEFO consume (Task 16b).
     *
     * Creates a BatchMovement record (negative qty) and decrements BatchStock.
     * Does NOT touch aggregate stock levels — that's handled by WAC service.
     *
     * @param  numeric-string  $quantity  Positive quantity to issue (will be negated internally)
     *
     * @throws InsufficientBatchStockException If the batch has insufficient stock
     */
    public function issueBatchStock(
        string $tenantId,
        int $batchId,
        string $locationId,
        string $quantity,
        ?string $movementId = null,
    ): void {
        DB::transaction(function () use ($tenantId, $batchId, $locationId, $quantity, $movementId): void {
            // Lock batch stock for update — strict, atomic single-batch issue.
            $batchStock = BatchStock::where('batch_id', $batchId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $available = $batchStock !== null ? (string) $batchStock->available_quantity : '0.0000';

            if (bccomp($available, $quantity, 4) < 0) {
                /** @var numeric-string $shortfall */
                $shortfall = bcsub($quantity, $available, 4); // precision-ok: batch quantity is decimal(15,4), canonical scale 4
                throw new InsufficientBatchStockException(
                    shortfall: $shortfall,
                    message: "Insufficient batch stock. Batch ID: {$batchId}, Available: {$available}, Requested: {$quantity}",
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
