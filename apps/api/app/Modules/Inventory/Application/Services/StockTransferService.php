<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Inventory\Domain\Events\StockTransferCancelled;
use App\Modules\Inventory\Domain\Events\StockTransferCompleted;
use App\Modules\Inventory\Domain\Events\StockTransferInitiated;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Exceptions\TransferStateException;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
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

    /**
     * Intermediate scale for transfer-cost allocation arithmetic. Matches the
     * WAC service's internal COST_SCALE (6) so the freight share carried into
     * recordCostAdjustment is at the same working precision.
     */
    private const ALLOCATION_SCALE = 6;

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly WeightedAverageCostService $wacService,
        private readonly ProductCostLock $costLock,
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

        return DB::transaction(function () use ($data): StockTransfer {
            // Idempotency check FIRST — if the same key was used, return the
            // existing transfer. Retry-safe by design.
            if ($data->idempotencyKey !== null) {
                $existing = StockTransfer::query()
                    ->where('tenant_id', $data->tenantId)
                    ->where('company_id', $data->companyId)
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

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

            $transferNumber = $data->transferNumber ?? $this->generateTransferNumber($data->tenantId, $data->companyId);

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

            foreach ($data->lines as $line) {
                if (bccomp($line->quantity, '0', self::QTY_SCALE) <= 0) {
                    throw new InvalidArgumentException('Each transfer line must have quantity greater than zero.');
                }

                StockTransferLine::create([
                    'id' => Str::uuid()->toString(),
                    'transfer_id' => $transfer->id,
                    'tenant_id' => $data->tenantId,
                    'company_id' => $data->companyId,
                    'product_id' => $line->productId,
                    'quantity' => $line->quantity,
                ]);
            }

            // Move stock into in_transit immediately.
            return $this->moveSourceToInTransit($transfer->id, $data->initiatedByUserId);
        }, attempts: 3);
    }

    /**
     * Complete the transfer: increment destination stock, capitalize any
     * transfer_cost into company-wide WAC, mark as completed.
     *
     * @throws TransferStateException when transfer is not in_transit
     */
    public function complete(string $transferId, string $userId): StockTransfer
    {
        return DB::transaction(function () use ($transferId, $userId): StockTransfer {
            $transfer = $this->lockTransfer($transferId);

            if (! $transfer->status->canBeCompleted()) {
                throw new TransferStateException($transfer->id, $transfer->status, 'complete');
            }

            // Multi-product deadlock defense: acquire ALL line product advisory
            // locks UP-FRONT in ONE sorted call (ProductCostLock sorts internally)
            // before the per-line receive()/recordCostAdjustment() loop. Those
            // nested per-line acquire([singleId]) calls just re-acquire the
            // already-held xact advisory locks (PG advisory locks are re-entrant,
            // auto-released at tx end). Without this, two transfers with lines
            // [A,B] vs [B,A] acquire in opposite order and deadlock; attempts:3
            // retries the same order and never breaks it.
            $productIds = $this->lineProductIds($transfer);

            return $this->costLock->acquire($transfer->tenant_id, $transfer->company_id, $productIds, function () use ($transfer, $userId): StockTransfer {
                return $this->completeLocked($transfer, $userId);
            });
        }, attempts: 3);
    }

    /**
     * Inner body of complete(), run with all line product advisory locks held
     * up-front in canonical sorted order (see complete()).
     */
    private function completeLocked(StockTransfer $transfer, string $userId): StockTransfer
    {
        $reference = $transfer->transfer_number;
        foreach ($transfer->lines as $line) {
            // load unlocked — receive()/recordCostAdjustment() take advisory-then-product-row lock in the canonical order; pre-locking the product row here would invert the order vs recordPurchase and deadlock.
            $product = Product::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->findOrFail($line->product_id);

            $this->stockAdjustmentService->receive(
                productId: $product->id,
                locationId: $transfer->destination_location_id,
                quantity: (string) $line->quantity,
                reference: $reference,
                userId: $userId,
                expectedCompanyId: $transfer->company_id,
            );

            $this->relabelLatestMovement(
                reference: $reference,
                productId: $product->id,
                locationId: $transfer->destination_location_id,
                fromType: MovementType::Receipt,
                toType: MovementType::TransferIn,
                transferId: $transfer->id,
            );
        }

        // Persist the Completed status BEFORE capitalizing the transfer cost.
        // recordCostAdjustment re-queries stock_transfers for in-transit
        // quantity; if this transfer is still InTransit at that point its
        // just-received qty is double-counted (in_transit + on_hand),
        // inflating the denominator and under-capitalizing the freight cost.
        $transfer->status = TransferStatus::Completed;
        $transfer->completed_by_user_id = $userId;
        $transfer->completed_at = now();
        $transfer->save();

        // Keep transfer_cost as a numeric-string; allocation arithmetic is
        // done in bcmath at the working scale (see capitalizeTransferCost).
        /** @var numeric-string $transferCost */
        $transferCost = (string) $transfer->transfer_cost;
        if (bccomp($transferCost, '0', self::ALLOCATION_SCALE) > 0) {
            $this->capitalizeTransferCost($transfer, $transferCost);
        }

        $transferSnapshot = $transfer->fresh(['lines']) ?? $transfer;

        DB::afterCommit(function () use ($transferSnapshot, $userId): void {
            event(new StockTransferCompleted(
                transferId: $transferSnapshot->id,
                tenantId: $transferSnapshot->tenant_id,
                companyId: $transferSnapshot->company_id,
                transferNumber: $transferSnapshot->transfer_number,
                sourceLocationId: $transferSnapshot->source_location_id,
                destinationLocationId: $transferSnapshot->destination_location_id,
                transferCost: (string) $transferSnapshot->transfer_cost,
                completedByUserId: $userId,
                occurredAt: now()->toIso8601String(),
            ));
        });

        return $transferSnapshot;
    }

    /**
     * Cancel the transfer. From draft = no stock motion. From in_transit =
     * return the in-flight stock back to source.
     *
     * @throws TransferStateException when transfer is already terminal
     */
    public function cancel(string $transferId, string $userId, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($transferId, $userId, $reason): StockTransfer {
            $transfer = $this->lockTransfer($transferId);

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
                $productIds = $this->lineProductIds($transfer);

                $this->costLock->acquire($transfer->tenant_id, $transfer->company_id, $productIds, function () use ($transfer, $userId, $cancelReference): void {
                    foreach ($transfer->lines as $line) {
                        $this->stockAdjustmentService->receive(
                            productId: $line->product_id,
                            locationId: $transfer->source_location_id,
                            quantity: (string) $line->quantity,
                            reference: $cancelReference,
                            userId: $userId,
                            expectedCompanyId: $transfer->company_id,
                        );

                        $this->relabelLatestMovement(
                            reference: $cancelReference,
                            productId: $line->product_id,
                            locationId: $transfer->source_location_id,
                            fromType: MovementType::Receipt,
                            toType: MovementType::TransferIn,
                            transferId: $transfer->id,
                        );
                    }
                });
            }

            $transfer->status = TransferStatus::Cancelled;
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
    private function moveSourceToInTransit(string $transferId, string $userId): StockTransfer
    {
        $transfer = $this->lockTransfer($transferId);

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

            /** @var numeric-string $costAtSend */
            $costAtSend = (string) ($product->cost_price ?? '0');

            $stockLevel = StockLevel::query()
                ->where('product_id', $product->id)
                ->where('location_id', $transfer->source_location_id)
                ->where('company_id', $transfer->company_id)
                ->lockForUpdate()
                ->first();

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

            $this->stockAdjustmentService->issue(
                productId: $product->id,
                locationId: $transfer->source_location_id,
                quantity: (string) $line->quantity,
                reference: $reference,
                userId: $userId,
                expectedCompanyId: $transfer->company_id,
            );

            $this->relabelLatestMovement(
                reference: $reference,
                productId: $product->id,
                locationId: $transfer->source_location_id,
                fromType: MovementType::Issue,
                toType: MovementType::TransferOut,
                transferId: $transfer->id,
            );

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

    /**
     * Allocate transfer_cost across lines per the chosen distribution and
     * capitalize each share into the company-wide WAC of the line's product.
     *
     * @param  numeric-string  $transferCost
     */
    private function capitalizeTransferCost(StockTransfer $transfer, string $transferCost): void
    {
        $working = self::ALLOCATION_SCALE;

        $weights = $this->computeAllocationWeights($transfer);
        /** @var numeric-string $totalWeight */
        $totalWeight = '0';
        foreach ($weights as $weight) {
            $totalWeight = bcadd($totalWeight, $weight, $working);
        }

        $lineCount = max(1, $transfer->lines->count());

        // First pass: compute each line's allocation, skipping genuinely-zero
        // shares. The LAST cost-bearing line absorbs the residual
        // (transferCost − Σ others) so the allocations sum to transferCost
        // EXACTLY, with no millième lost or gained to independent rounding.
        /** @var list<array{line: StockTransferLine, product: Product, allocated: numeric-string}> $allocations */
        $allocations = [];
        foreach ($transfer->lines as $line) {
            $product = Product::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->findOrFail($line->product_id);

            if (bccomp($totalWeight, '0', $working) > 0) {
                $allocated = bcmul($transferCost, bcdiv($weights[$line->id], $totalWeight, $working), $working);
            } else {
                $allocated = bcdiv($transferCost, (string) $lineCount, $working);
            }

            if (bccomp($allocated, '0', $working) <= 0) {
                continue;
            }

            $allocations[] = ['line' => $line, 'product' => $product, 'allocated' => $allocated];
        }

        $lastIndex = count($allocations) - 1;

        // Reconcile at the PERSISTED scale (QTY_SCALE = 4), NOT the 6-dp working
        // scale. allocated_transfer_cost is stored at 4 dp and recordCostAdjustment
        // capitalizes the SAME value; reconciling the residual at 6 dp and then
        // truncating to 4 dp on persist loses a millième per line (e.g. 7 equal
        // lines of 100 → 14.2857 × 7 = 99.9999 < 100). Every line EXCEPT the last
        // is formatted to 4 dp; the last absorbs (transferCost − Σ others) at 4 dp
        // so Σ persisted == transferCost EXACTLY at the stored scale.
        /** @var numeric-string $transferCost4dp */
        $transferCost4dp = CurrencyScale::bcformat($transferCost, self::QTY_SCALE);
        /** @var numeric-string $runningSumOfOthers4dp */
        $runningSumOfOthers4dp = '0';

        foreach ($allocations as $index => $allocation) {
            $line = $allocation['line'];
            $product = $allocation['product'];

            $allocated4dp = $index === $lastIndex
                ? bcsub($transferCost4dp, $runningSumOfOthers4dp, self::QTY_SCALE)
                : CurrencyScale::bcformat($allocation['allocated'], self::QTY_SCALE);

            $runningSumOfOthers4dp = bcadd($runningSumOfOthers4dp, $allocated4dp, self::QTY_SCALE);

            $line->allocated_transfer_cost = $allocated4dp;
            $line->save();

            $this->wacService->recordCostAdjustment(
                product: $product,
                // The (float) here is on a LOCAL bcmath numeric-string (the SAME
                // 4-dp value persisted to allocated_transfer_cost, not a decimal
                // property), so PHPStan does not flag it. This float boundary is
                // the existing WAC API; recordCostAdjustment immediately
                // re-stringifies via CurrencyScale::bcformat. Passing the 4-dp
                // value keeps stored sum and capitalized sum both == transferCost.
                additionalCost: (float) $allocated4dp,
                reason: 'stock_transfer_cost',
                tenantId: $transfer->tenant_id,
                companyId: $transfer->company_id,
                reference: $transfer->transfer_number,
                referenceType: StockTransfer::class,
                referenceId: $transfer->id,
            );
        }
    }

    /**
     * @return array<string, numeric-string>
     */
    private function computeAllocationWeights(StockTransfer $transfer): array
    {
        $working = self::ALLOCATION_SCALE;

        $weights = [];
        foreach ($transfer->lines as $line) {
            // Read decimal-cast attributes as numeric-strings (no float cast).
            $qty = (string) $line->quantity;
            $cost = (string) ($line->unit_cost_snapshot ?? '0');

            $weights[$line->id] = match ($transfer->transfer_cost_distribution) {
                TransferCostDistribution::ProRataValue => bcmul($qty, $cost, $working),
                TransferCostDistribution::ProRataQuantity => CurrencyScale::bcformat($qty, $working),
                TransferCostDistribution::EqualPerLine => '1',
            };
        }

        return $weights;
    }

    /**
     * Re-label the most recent StockMovement created by the stock-adjustment
     * service to a transfer-specific movement type and anchor it to the
     * StockTransfer aggregate via reference_type/reference_id.
     */
    private function relabelLatestMovement(
        string $reference,
        string $productId,
        string $locationId,
        MovementType $fromType,
        MovementType $toType,
        string $transferId,
    ): void {
        StockMovement::query()
            ->where('reference', $reference)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('movement_type', $fromType)
            ->whereNull('reference_id')
            ->orderByDesc('created_at')
            ->limit(1)
            ->update([
                'movement_type' => $toType->value,
                'reference_type' => StockTransfer::class,
                'reference_id' => $transferId,
            ]);
    }

    private function lockTransfer(string $transferId): StockTransfer
    {
        /** @var StockTransfer $transfer */
        $transfer = StockTransfer::query()
            ->with('lines')
            ->lockForUpdate()
            ->findOrFail($transferId);

        return $transfer;
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
     * Unique product ids across a transfer's lines, as a typed list of strings
     * for the ProductCostLock sorted-acquire (deadlock defense).
     *
     * @return list<string>
     */
    private function lineProductIds(StockTransfer $transfer): array
    {
        $ids = [];
        foreach ($transfer->lines as $line) {
            $ids[(string) $line->product_id] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param  list<InitiateTransferLineData>  $lines
     * @return list<string>
     */
    private function collectProductIds(array $lines): array
    {
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->productId])) {
                throw new InvalidArgumentException('Duplicate product on transfer lines: '.$line->productId);
            }
            $seen[$line->productId] = true;
        }

        return array_keys($seen);
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
