<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\DTOs\InitiateTransferBatchAllocationData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Inventory\Domain\Events\StockTransferCancelled;
use App\Modules\Inventory\Domain\Events\StockTransferInitiated;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Exceptions\TransferReceiptFailureException;
use App\Modules\Inventory\Domain\Exceptions\TransferStateException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Inventory\Domain\StockTransferLineBatchAllocation;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orchestrates the full lifecycle of a stock transfer document (intracompany).
 *
 * Status flow: draft -> in_transit -> completed | cancelled
 *
 * - initiate(): atomically locks source stock, decrements it, snapshots
 *               unit_cost_at_send per line, records TransferOut movements,
 *               emits StockTransferInitiated.
 * - complete(): atomically increments destination stock, records TransferIn
 *               movements, capitalizes transfer_cost into company-wide WAC
 *               per the configured TransferCostDistribution, emits
 *               StockTransferCompleted.
 * - cancel():   from draft = no stock motion. From in_transit = returns the
 *               in-flight stock back to source. Emits StockTransferCancelled.
 *
 * All writes are scoped to (tenant_id, company_id). Source and destination
 * locations must belong to the same company. Cross-company transfers are
 * rejected (Scenario B inter-company is deferred to a follow-on track).
 *
 * Idempotency: when the caller provides an idempotency_key, repeating the
 * initiate call with the same key returns the existing transfer instead
 * of creating a new one — safe for client retries.
 */
class StockTransferService
{
    private const QTY_SCALE = 4;

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly ProductCostLock $costLock,
        private readonly ProductVariantLookup $variantLookup,
        private readonly StockTransferMovementSupport $movementSupport,
        private readonly StockTransferReceiptService $receiptService,
    ) {}

    /**
     * Create a draft transfer and immediately move the source stock into
     * `in_transit`. Returns the transfer with status=in_transit.
     *
     * @throws InvalidArgumentException for invariant violations
     * @throws InsufficientStockException when source lacks the requested qty
     */
    public function initiate(InitiateTransferData $data): StockTransfer
    {
        if (count($data->lines) === 0) {
            throw new InvalidArgumentException('Transfer must have at least one line.');
        }

        if ($data->sourceLocationId === $data->destinationLocationId) {
            throw new InvalidArgumentException('Source and destination must be different.');
        }

        if ($data->transferType === TransferType::Intercompany) {
            // Scenario B is deferred to a follow-on track; reject at the seam
            // so the surface stays honest about what we support today.
            throw new InvalidArgumentException(
                'Inter-company transfers are not yet supported. Use Intracompany.'
            );
        }

        try {
            return DB::transaction(function () use ($data): StockTransfer {
                // Idempotency check FIRST — if the same key was used, return the
                // existing transfer. Retry-safe by design.
                if ($data->idempotencyKey !== null) {
                    $existing = $this->findExistingTransfer($data);

                    if ($existing !== null) {
                        return $existing->loadMissing('lines');
                    }
                }

                $source = $this->loadLocationOrFail($data->sourceLocationId, $data->companyId, 'source');
                $destination = $this->loadLocationOrFail($data->destinationLocationId, $data->companyId, 'destination');

                if ($source->company_id !== $destination->company_id) {
                    throw new InvalidArgumentException('Source and destination locations must belong to the same company.');
                }

                $productIds = $this->collectProductIds($data->lines);
                $this->loadAndVerifyProducts($productIds, $data->tenantId, $data->companyId);

                // When the caller opts in, fill in the batch allocations for any
                // batch-tracked line that arrived without them, earliest-expiry
                // first (FEFO). Batch knowledge stays inside this module; callers
                // like replenishment fulfilment never query batch tables.
                $lines = $data->autoAllocateBatchesFefo
                    ? $this->resolveFefoAllocations($data)
                    : $data->lines;

                $transferNumber = $data->transferNumber ?? $this->generateTransferNumber($data->tenantId, $data->companyId);

                $transfer = $this->insertTransfer($data, $transferNumber);

                foreach ($lines as $line) {
                    if (bccomp($line->quantity, '0', self::QTY_SCALE) <= 0) {
                        throw new InvalidArgumentException('Each transfer line must have quantity greater than zero.');
                    }

                    $this->assertVariantValidForProduct($line->productId, $line->variantId);

                    $transferLine = StockTransferLine::create([
                        'id' => Str::uuid()->toString(),
                        'transfer_id' => $transfer->id,
                        'tenant_id' => $data->tenantId,
                        'company_id' => $data->companyId,
                        'product_id' => $line->productId,
                        'variant_id' => $line->variantId,
                        'quantity' => $line->quantity,
                    ]);

                    foreach ($line->batchAllocations as $allocation) {
                        StockTransferLineBatchAllocation::create([
                            'id' => Str::uuid()->toString(),
                            'stock_transfer_line_id' => $transferLine->id,
                            'tenant_id' => $data->tenantId,
                            'company_id' => $data->companyId,
                            'batch_id' => $allocation->batchId,
                            'quantity' => $allocation->quantity,
                        ]);
                    }
                }

                // Move stock into in_transit immediately.
                return $this->moveSourceToInTransit($transfer, $data->initiatedByUserId);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // ID-4: a concurrent caller committed the same logical transfer while
            // this attempt was in flight. The failed attempt (or its savepoint)
            // has already rolled back here, so the scoped reread below runs on a
            // usable connection and can see the winner's committed row.
            //
            // The constraint NAME is deliberately not inspected: a same-key race
            // also collides on stock_transfers_company_number_unique because both
            // callers generate the same COUNT()+1 number. The committed row at
            // (tenant_id, company_id, idempotency_key) is the sole replay
            // discriminator; anything else rethrows untouched.
            if ($data->idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->findExistingTransfer($data);
            if ($existing === null) {
                throw $exception;
            }

            return $existing->loadMissing('lines');
        }
    }

    /**
     * Scoped idempotency lookup. Protected so the collision harness can count
     * calls and observe the post-rollback transaction level.
     */
    protected function findExistingTransfer(InitiateTransferData $data): ?StockTransfer
    {
        return StockTransfer::query()
            ->where('tenant_id', $data->tenantId)
            ->where('company_id', $data->companyId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
    }

    /**
     * The transfer header INSERT. Protected so the collision harness can commit
     * a rival row on a second connection immediately before it runs.
     */
    protected function insertTransfer(InitiateTransferData $data, string $transferNumber): StockTransfer
    {
        /** @var StockTransfer $transfer */
        $transfer = StockTransfer::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $data->tenantId,
            'company_id' => $data->companyId,
            'transfer_number' => $transferNumber,
            'transfer_type' => $data->transferType,
            'status' => TransferStatus::Draft,
            'source_location_id' => $data->sourceLocationId,
            'destination_location_id' => $data->destinationLocationId,
            'notes' => $data->notes,
            'transfer_cost' => $data->transferCost,
            'transfer_cost_label' => $data->transferCostLabel,
            'transfer_cost_distribution' => $data->transferCostDistribution,
            'idempotency_key' => $data->idempotencyKey,
            'initiated_by_user_id' => $data->initiatedByUserId,
        ]);

        return $transfer;
    }

    /**
     * Validate aggregate source demand before a caller initiates a batch of
     * transfers. Callers that initiate after this check must keep the same
     * database transaction open so the locked stock rows stay pinned.
     *
     * @param  list<InitiateTransferLineData>  $lines
     */
    public function assertSourceAvailability(
        string $tenantId,
        string $companyId,
        string $sourceLocationId,
        array $lines,
    ): void {
        /** @var array<string, array{product_id: string, variant_id: ?string, quantity: numeric-string}> $required */
        $required = [];
        foreach ($lines as $line) {
            $key = $line->productId.'|'.($line->variantId ?? '');
            if (isset($required[$key])) {
                $required[$key]['quantity'] = bcadd(
                    $required[$key]['quantity'],
                    $line->quantity,
                    self::QTY_SCALE,
                );
            } else {
                $required[$key] = [
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'quantity' => $line->quantity,
                ];
            }
        }

        $levels = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $sourceLocationId)
            ->whereIn('product_id', array_values(array_unique(array_column($required, 'product_id'))))
            ->lockForUpdate()
            ->get();

        /** @var array<string, numeric-string> $availableByProductVariant */
        $availableByProductVariant = [];
        foreach ($levels as $level) {
            $key = $level->product_id.'|'.($level->variant_id ?? '');
            $availableByProductVariant[$key] = QuantityScale::round(
                $level->getAvailableQuantity(),
                self::QTY_SCALE,
                QuantityScale::FLOOR,
            );
        }

        foreach ($required as $key => $demand) {
            $available = $this->availableQuantity($availableByProductVariant, $key);
            if (bccomp($demand['quantity'], $available, self::QTY_SCALE) > 0) {
                throw new InsufficientStockException(
                    productId: $demand['product_id'],
                    locationId: $sourceLocationId,
                    requested: $demand['quantity'],
                    available: $available,
                );
            }
        }
    }

    /**
     * @param  array<string, numeric-string>  $availableByProductVariant
     * @return numeric-string
     */
    private function availableQuantity(array $availableByProductVariant, string $key): string
    {
        if (isset($availableByProductVariant[$key])) {
            return $availableByProductVariant[$key];
        }

        return '0.0000';
    }

    /**
     * Complete the transfer: increment destination stock, capitalize any
     * transfer_cost into company-wide WAC, mark as completed.
     *
     * @throws TransferStateException when transfer is not in_transit
     * @throws TransferReceiptFailureException
     */
    public function complete(StockTransfer $identity, string $userId): StockTransfer
    {
        $transferId = $identity->id;

        return $this->receiptService
            ->receiveAllRemaining($transferId, $userId, 'sys:complete:'.$transferId, $identity->tenant_id, $identity->company_id)
            ->transfer;
    }

    /**
     * Cancel the transfer. From draft = no stock motion. From in_transit =
     * return the in-flight stock back to source.
     *
     * @throws TransferStateException when transfer is already terminal
     */
    public function cancel(StockTransfer $identity, string $userId, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($identity, $userId, $reason): StockTransfer {
            $transfer = $this->movementSupport->lockTransfer($identity);

            if (! $transfer->status->canBeCancelled()) {
                throw new TransferStateException($transfer->id, $transfer->status, 'cancel');
            }

            $previousStatus = $transfer->status;
            $cancelReference = $transfer->transfer_number.'-CANCEL';

            if ($previousStatus === TransferStatus::InTransit) {
                // Same multi-product deadlock defense as complete(): the restock
                // path calls receive() per line, and receive() acquires a
                // per-product advisory lock. Acquire ALL line product locks
                // up-front in ONE sorted call so two cancels with lines [A,B] vs
                // [B,A] cannot AB-BA deadlock. Nested per-line acquires inside
                // receive() are re-entrant (released at tx end).
                $productIds = [];
                foreach ($transfer->lines as $line) {
                    $productIds[] = $line->product_id;
                }
                $productIds = array_values(array_unique($productIds));

                $this->costLock->acquire($transfer->tenant_id, $transfer->company_id, $productIds, function () use ($transfer, $userId, $cancelReference): void {
                    foreach ($transfer->lines as $line) {
                        $this->movementSupport->restockAtSource(
                            $transfer,
                            $line,
                            (string) $line->quantity,
                            $line->batchAllocations->pluck('quantity', 'batch_id')->all(),
                            $userId,
                            $cancelReference,
                        );
                    }
                });
            }

            $transfer->status = TransferStatus::Cancelled;
            $transfer->freight_uncapitalized = $transfer->transfer_cost;
            $transfer->cancelled_by_user_id = $userId;
            $transfer->cancelled_at = now();
            $transfer->cancellation_reason = $reason;
            $transfer->save();

            $transferSnapshot = $transfer->fresh(['lines']) ?? $transfer;

            DB::afterCommit(function () use ($transferSnapshot, $previousStatus, $userId, $reason): void {
                event(new StockTransferCancelled(
                    transferId: $transferSnapshot->id,
                    tenantId: $transferSnapshot->tenant_id,
                    companyId: $transferSnapshot->company_id,
                    transferNumber: $transferSnapshot->transfer_number,
                    previousStatus: $previousStatus,
                    cancelledByUserId: $userId,
                    reason: $reason,
                    occurredAt: now()->toIso8601String(),
                ));
            });

            return $transferSnapshot;
        }, attempts: 3);
    }

    /**
     * Decrement source stock for every line and emit StockTransferInitiated.
     * Called from initiate() — separated so the WAC snapshot / source-lock
     * loop stays focused.
     */
    private function moveSourceToInTransit(StockTransfer $identity, string $userId): StockTransfer
    {
        $transfer = $this->movementSupport->lockTransfer($identity);

        if (! $transfer->status->canBeInitiated()) {
            throw new TransferStateException($transfer->id, $transfer->status, 'initiate');
        }

        $reference = $transfer->transfer_number;

        foreach ($transfer->lines as $line) {
            // Point-in-time snapshot read of cost_price for unit_cost_snapshot.
            // No row lock: this is a pure decrement path and the only row lock
            // taken is the source stock_level (via issue() -> lockStockLevel).
            // Locking the product row here would invert the canonical order
            // (advisory -> stock_level -> product) and deadlock.
            $product = Product::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->findOrFail($line->product_id);

            $stockLevel = StockLevel::query()
                ->where('product_id', $product->id)
                ->where('location_id', $transfer->source_location_id)
                ->where('company_id', $transfer->company_id)
                ->when(
                    $line->variant_id !== null,
                    fn ($q) => $q->where('variant_id', $line->variant_id),
                    fn ($q) => $q->whereNull('variant_id'),
                )
                ->lockForUpdate()
                ->first();

            // Snapshot cost_price AFTER the source stock_level row is locked, so the
            // dispatch cost is captured against the pinned row (tighter snapshot window).
            // Product stays unlocked — no inversion of advisory -> stock_level -> product.
            /** @var numeric-string $costAtSend */
            $costAtSend = (string) ($product->cost_price ?? '0');

            /** @var numeric-string $available */
            $available = $stockLevel === null
                ? '0.0000'
                : $stockLevel->getAvailableQuantity();

            if (bccomp((string) $line->quantity, $available, self::QTY_SCALE) > 0) {
                throw new InsufficientStockException(
                    productId: $product->id,
                    locationId: $transfer->source_location_id,
                    requested: (string) $line->quantity,
                    available: $available,
                );
            }

            if ($line->batchAllocations->isNotEmpty() || $product->requires_batch_tracking) {
                $this->assertBatchAllocationsCanIssue($line, $product, $transfer);

                foreach ($line->batchAllocations as $allocation) {
                    $this->stockAdjustmentService->issue(
                        productId: $product->id,
                        locationId: $transfer->source_location_id,
                        quantity: (string) $allocation->quantity,
                        reference: $reference,
                        userId: $userId,
                        batchId: $allocation->batch_id,
                        expectedCompanyId: $transfer->company_id,
                        variantId: $line->variant_id,
                        movementType: MovementType::TransferOut,
                        transferId: $transfer->id,
                    );
                }
            } else {
                $this->stockAdjustmentService->issue(
                    productId: $product->id,
                    locationId: $transfer->source_location_id,
                    quantity: (string) $line->quantity,
                    reference: $reference,
                    userId: $userId,
                    expectedCompanyId: $transfer->company_id,
                    variantId: $line->variant_id,
                    movementType: MovementType::TransferOut,
                    transferId: $transfer->id,
                );
            }

            $line->unit_cost_snapshot = $costAtSend;
            $line->save();
        }

        $transfer->status = TransferStatus::InTransit;
        $transfer->initiated_at = now();
        $transfer->save();

        $transferSnapshot = $transfer->fresh(['lines']) ?? $transfer;

        DB::afterCommit(function () use ($transferSnapshot, $userId): void {
            event(new StockTransferInitiated(
                transferId: $transferSnapshot->id,
                tenantId: $transferSnapshot->tenant_id,
                companyId: $transferSnapshot->company_id,
                transferNumber: $transferSnapshot->transfer_number,
                transferType: $transferSnapshot->transfer_type->value,
                sourceLocationId: $transferSnapshot->source_location_id,
                destinationLocationId: $transferSnapshot->destination_location_id,
                initiatedByUserId: $userId,
                occurredAt: now()->toIso8601String(),
            ));
        });

        return $transferSnapshot;
    }

    private function assertBatchAllocationsCanIssue(
        StockTransferLine $line,
        Product $product,
        StockTransfer $transfer,
    ): void {
        if ($line->batchAllocations->isEmpty()) {
            throw new InvalidArgumentException('Batch-tracked products require batch allocations.');
        }

        /** @var numeric-string $allocatedQuantity */
        $allocatedQuantity = '0';
        $seenBatchIds = [];

        foreach ($line->batchAllocations as $allocation) {
            if (isset($seenBatchIds[$allocation->batch_id])) {
                throw new InvalidArgumentException('Each batch can only be allocated once per transfer line.');
            }
            $seenBatchIds[$allocation->batch_id] = true;

            $allocatedQuantity = bcadd($allocatedQuantity, (string) $allocation->quantity, self::QTY_SCALE);
            $this->assertBatchCanIssue($allocation, $product, $transfer, $line->variant_id);
        }

        if (bccomp($allocatedQuantity, (string) $line->quantity, self::QTY_SCALE) !== 0) {
            throw new InvalidArgumentException('Batch allocation quantity must equal the transfer line quantity.');
        }

        // Server is the FEFO guarantee: the submitted split must match the
        // canonical earliest-expiry-first allocation. Client FEFO is only a
        // convenience; an API caller cannot ship a later-expiry lot while an
        // earlier-expiry sellable lot still has stock at the source.
        $this->assertAllocationsFollowFefo($line, $product, $transfer);
    }

    /**
     * Auto-fill FEFO batch allocations for every batch-tracked line that
     * arrived without them (the opt-in `autoAllocateBatchesFefo` path). Lines
     * that are not batch-tracked, or that already carry explicit allocations,
     * pass through untouched.
     *
     * Locks the source's batch-stock rows via computeFefoSplit(); the caller
     * runs inside initiate()'s transaction so those locks are held through the
     * subsequent assertAllocationsFollowFefo()/issue() re-reads.
     *
     * LOCK-ORDER CONTRACT: callers that opt in to autoAllocateBatchesFefo must
     * lock the source stock_levels rows FIRST (e.g. via assertSourceAvailability())
     * before initiate() runs this method. This path locks BatchStock before the
     * later stock_levels lock in moveSourceToInTransit(); without the caller's
     * up-front stock_levels lock the order inverts vs the manual-allocation path
     * (stock_levels -> BatchStock) and concurrent transfers can ABBA-deadlock.
     *
     * @return list<InitiateTransferLineData>
     */
    private function resolveFefoAllocations(InitiateTransferData $data): array
    {
        $productIds = $this->collectProductIds($data->lines);

        /** @var array<string, bool> $batchTracked */
        $batchTracked = [];
        foreach (
            Product::query()
                ->where('tenant_id', $data->tenantId)
                ->where('company_id', $data->companyId)
                ->whereIn('id', $productIds)
                ->where('requires_batch_tracking', true)
                ->pluck('id') as $id
        ) {
            $batchTracked[(string) $id] = true;
        }

        /** @var list<InitiateTransferLineData> $resolved */
        $resolved = [];
        foreach ($data->lines as $line) {
            if (! isset($batchTracked[$line->productId]) || count($line->batchAllocations) > 0) {
                $resolved[] = $line;

                continue;
            }

            $split = $this->computeFefoSplit(
                productId: $line->productId,
                variantId: $line->variantId,
                quantity: $line->quantity,
                tenantId: $data->tenantId,
                companyId: $data->companyId,
                sourceLocationId: $data->sourceLocationId,
            );

            if (bccomp($split['remaining'], '0', self::QTY_SCALE) > 0) {
                // The sellable batch stock cannot cover the line. Route through
                // the same InsufficientStockException the non-batch path uses so
                // the endpoint maps it to a 422 INSUFFICIENT_STOCK envelope.
                throw new InsufficientStockException(
                    productId: $line->productId,
                    locationId: $data->sourceLocationId,
                    requested: $line->quantity,
                    available: bcsub($line->quantity, $split['remaining'], self::QTY_SCALE),
                );
            }

            /** @var list<InitiateTransferBatchAllocationData> $allocations */
            $allocations = [];
            foreach ($split['allocations'] as $batchId => $qty) {
                $allocations[] = new InitiateTransferBatchAllocationData($batchId, $qty);
            }

            $resolved[] = new InitiateTransferLineData(
                productId: $line->productId,
                quantity: $line->quantity,
                variantId: $line->variantId,
                batchAllocations: $allocations,
            );
        }

        return $resolved;
    }

    /**
     * Compute the canonical FEFO (earliest-expiry-first) split of $quantity
     * across the sellable batches at the source location. Locks the batch-stock
     * rows it reads. Batches that cannot be sold (expired / recalled / inactive)
     * are skipped, matching the transfer-issue convention.
     *
     * @param  numeric-string  $quantity
     * @return array{allocations: array<int, numeric-string>, remaining: numeric-string}
     *                                                                                   `allocations` is batchId => quantity earliest-expiry first; `remaining`
     *                                                                                   is the shortfall (> 0 means the sellable stock could not cover $quantity).
     */
    private function computeFefoSplit(
        string $productId,
        ?string $variantId,
        string $quantity,
        string $tenantId,
        string $companyId,
        string $sourceLocationId,
    ): array {
        $batches = Batch::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->when(
                $variantId !== null,
                fn ($q) => $q->where('variant_id', $variantId),
                fn ($q) => $q->whereNull('variant_id'),
            )
            // W4-1: undated lots rank AFTER every dated lot. This ordering IS the
            // FEFO rule the transfer guard enforces ("Batch allocations must follow
            // FEFO"), so an invented expiry here does not merely mis-sort — it
            // compels the operator to ship the wrong lot.
            ->orderByRaw('(expiry_date IS NULL) ASC, expiry_date ASC')
            ->orderBy('id')
            ->get();

        /** @var array<int, numeric-string> $available earliest-expiry first */
        $available = [];
        foreach ($batches as $batch) {
            if (! $batch->canBeSold()) {
                continue;
            }

            $batchStock = BatchStock::query()
                ->where('tenant_id', $tenantId)
                ->where('batch_id', $batch->id)
                ->where('location_id', $sourceLocationId)
                ->lockForUpdate()
                ->first();

            $qty = $batchStock === null
                ? '0.0000'
                : bcsub((string) $batchStock->quantity, (string) $batchStock->reserved_quantity, self::QTY_SCALE);

            if (bccomp($qty, '0', self::QTY_SCALE) > 0) {
                $available[(int) $batch->id] = $qty;
            }
        }

        /** @var numeric-string $remaining */
        $remaining = $quantity;
        /** @var array<int, numeric-string> $allocations */
        $allocations = [];
        foreach ($available as $batchId => $qty) {
            if (bccomp($remaining, '0', self::QTY_SCALE) <= 0) {
                break;
            }
            $take = bccomp($qty, $remaining, self::QTY_SCALE) < 0 ? $qty : $remaining;
            $allocations[$batchId] = $take;
            $remaining = bcsub($remaining, $take, self::QTY_SCALE);
        }

        return ['allocations' => $allocations, 'remaining' => $remaining];
    }

    /**
     * Enforce FEFO ordering: the line's batch allocations must equal the
     * canonical earliest-expiry-first split of the line quantity across the
     * sellable batches available at the source location.
     */
    private function assertAllocationsFollowFefo(
        StockTransferLine $line,
        Product $product,
        StockTransfer $transfer,
    ): void {
        $split = $this->computeFefoSplit(
            productId: $product->id,
            variantId: $line->variant_id,
            quantity: (string) $line->quantity,
            tenantId: $transfer->tenant_id,
            companyId: $transfer->company_id,
            sourceLocationId: $transfer->source_location_id,
        );

        /** @var array<int, numeric-string> $expected */
        $expected = $split['allocations'];

        if (bccomp($split['remaining'], '0', self::QTY_SCALE) > 0) {
            throw new InvalidArgumentException('Insufficient sellable batch stock at the source to fulfil the transfer line under FEFO.');
        }

        /** @var array<int, numeric-string> $submitted */
        $submitted = [];
        foreach ($line->batchAllocations as $allocation) {
            $submitted[(int) $allocation->batch_id] = (string) $allocation->quantity;
        }

        $matches = count($submitted) === count($expected);
        if ($matches) {
            foreach ($expected as $batchId => $qty) {
                if (! isset($submitted[$batchId]) || bccomp($submitted[$batchId], $qty, self::QTY_SCALE) !== 0) {
                    $matches = false;
                    break;
                }
            }
        }

        if (! $matches) {
            throw new InvalidArgumentException('Batch allocations must follow FEFO (earliest expiry first).');
        }
    }

    private function assertBatchCanIssue(
        StockTransferLineBatchAllocation $allocation,
        Product $product,
        StockTransfer $transfer,
        ?string $variantId,
    ): void {
        $batch = Batch::query()
            ->where('tenant_id', $transfer->tenant_id)
            ->where('company_id', $transfer->company_id)
            ->where('product_id', $product->id)
            ->when(
                $variantId !== null,
                fn ($q) => $q->where('variant_id', $variantId),
                fn ($q) => $q->whereNull('variant_id'),
            )
            ->find($allocation->batch_id);

        if ($batch === null) {
            throw new InvalidArgumentException('Batch allocation does not belong to the transfer product.');
        }

        if (! $batch->canBeSold()) {
            throw new InvalidArgumentException('Batch cannot be transferred because it is expired, recalled, or inactive.');
        }

        $batchStock = BatchStock::query()
            ->where('tenant_id', $transfer->tenant_id)
            ->where('batch_id', $allocation->batch_id)
            ->where('location_id', $transfer->source_location_id)
            ->lockForUpdate()
            ->first();

        /** @var numeric-string $available */
        $available = $batchStock === null
            ? '0.0000'
            : bcsub((string) $batchStock->quantity, (string) $batchStock->reserved_quantity, self::QTY_SCALE);

        if (bccomp((string) $allocation->quantity, $available, self::QTY_SCALE) > 0) {
            throw new InvalidArgumentException(
                "Insufficient batch stock for transfer. Batch ID: {$allocation->batch_id}, Available: {$available}, Requested: {$allocation->quantity}"
            );
        }
    }

    private function loadLocationOrFail(string $locationId, string $companyId, string $side): Location
    {
        $location = Location::query()
            ->where('company_id', $companyId)
            ->find($locationId);

        if ($location === null) {
            throw new InvalidArgumentException("The {$side} location does not belong to the current company.");
        }

        return $location;
    }

    /**
     * @param  list<InitiateTransferLineData>  $lines
     * @return list<string>
     */
    private function collectProductIds(array $lines): array
    {
        $seen = [];
        $productIds = [];
        foreach ($lines as $line) {
            // A product may appear on multiple lines under DIFFERENT variants;
            // only a duplicate (product, variant) pair is rejected. The DB
            // partial-unique on (transfer_id, product_id, variant_id) enforces
            // the same invariant at the storage layer.
            $key = $line->productId.'|'.($line->variantId ?? '');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate product/variant on transfer lines: '.$line->productId);
            }
            $seen[$key] = true;
            $productIds[$line->productId] = true;
        }

        return array_keys($productIds);
    }

    /**
     * @param  list<string>  $productIds
     */
    private function loadAndVerifyProducts(array $productIds, string $tenantId, string $companyId): void
    {
        $found = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->all();

        $missing = array_values(array_diff($productIds, $found));
        if (count($missing) > 0) {
            throw new InvalidArgumentException(
                'Products do not belong to the current company: '.implode(', ', $missing)
            );
        }
    }

    /**
     * Validate the line's variant addressing:
     *  - if a variantId is supplied, it must exist, be active, and belong to
     *    the product;
     *  - if it is null but the product has active variants, reject (mirrors
     *    StockAdjustmentService::assertVariantConsistency, but surfaced as an
     *    InvalidArgumentException so the controller maps it to 422 instead of
     *    letting the seam's uncaught VariantRequiredException 500).
     */
    private function assertVariantValidForProduct(string $productId, ?string $variantId): void
    {
        if ($variantId === null) {
            if ($this->variantLookup->listForProduct($productId, true)->isNotEmpty()) {
                throw new InvalidArgumentException(
                    "Product {$productId} has active variants; a variant_id is required for the transfer line."
                );
            }

            return;
        }

        $summary = $this->variantLookup->findById($variantId);
        if ($summary === null || ! $summary->isActive || $summary->productId !== $productId) {
            throw new InvalidArgumentException(
                "Variant {$variantId} is invalid for product {$productId}."
            );
        }
    }

    private function generateTransferNumber(string $tenantId, string $companyId): string
    {
        $year = now()->format('Y');
        $count = StockTransfer::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereYear('created_at', $year)
            ->count();

        return sprintf('TR-%s-%05d', $year, $count + 1);
    }
}
