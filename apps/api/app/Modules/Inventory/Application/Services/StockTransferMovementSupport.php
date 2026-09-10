<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Shared transfer stock and freight operations. Callers own the transaction
 * and product locks; transfer movements carry their final linkage at insertion.
 */
final class StockTransferMovementSupport
{
    /** Intermediate scale for transfer-cost allocation arithmetic; moved from StockTransferService:70. */
    public const int ALLOCATION_SCALE = 6;

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly WeightedAverageCostService $wacService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Header row lock with lines and allocations eager-loaded.
     * Re-resolve the caller-scoped identity under the header lock.
     */
    public function lockTransfer(StockTransfer $identity): StockTransfer
    {
        if (! Str::isUuid($identity->id)) {
            throw (new ModelNotFoundException)->setModel(StockTransfer::class, [$identity->id]);
        }
        /** @var StockTransfer $transfer */
        $transfer = StockTransfer::query()
            ->where('tenant_id', $identity->tenant_id)
            ->where('company_id', $identity->company_id)
            ->with('lines.batchAllocations')
            ->lockForUpdate()
            ->findOrFail($identity->id);

        return $transfer;
    }

    /**
     * Per-line allocation weights for the chosen distribution.
     * Moved from StockTransferService::computeAllocationWeights() (:714-732) with ONE
     * change, stated in §7.11: the weight source becomes the LANDED quantity supplied
     * by the caller instead of `$line->quantity`, so a partially-received transfer
     * allocates freight on what arrived.
     *
     * @param  array<string, numeric-string>  $landedQuantityByLineId  quantity_received per line id
     * @return array<string, numeric-string>
     */
    public function computeAllocationWeights(StockTransfer $transfer, array $landedQuantityByLineId): array
    {
        $working = max(self::ALLOCATION_SCALE, $this->scaleResolver->getScaleSafe($transfer->company->currency, 3) + 4);

        $weights = [];
        foreach ($transfer->lines as $line) {
            /** @var numeric-string $qty */
            $qty = $landedQuantityByLineId[(string) $line->id] ?? '0';
            /** @var numeric-string $cost */
            $cost = (string) ($line->unit_cost_snapshot ?? '0');

            $weights[$line->id] = match ($transfer->transfer_cost_distribution) {
                TransferCostDistribution::ProRataValue => bcmul($qty, $cost, $working),
                TransferCostDistribution::ProRataQuantity => CurrencyScale::bcformat($qty, $working),
                TransferCostDistribution::EqualPerLine => bccomp($qty, '0', $working) > 0 ? '1' : '0',
            };
        }

        return $weights;
    }

    /**
     * Allocate `$pool` across the lines on landed weights and capitalise each share
     * into the company-wide WAC of the line's product. The LAST cost-bearing line
     * absorbs the residual so the allocations sum to `$pool` EXACTLY.
     * Receipt freight uses §7.11 currency-dependent precision and only good-landed
     * lines. Zero value weights fall back to equal shares among those lines.
     *
     * @param  numeric-string  $pool
     * @param  array<string, numeric-string>  $landedQuantityByLineId
     */
    public function capitalizeTransferCost(StockTransfer $transfer, string $pool, array $landedQuantityByLineId): void
    {
        $working = max(self::ALLOCATION_SCALE, $this->scaleResolver->getScaleSafe($transfer->company->currency, 3) + 4);

        $weights = $this->computeAllocationWeights($transfer, $landedQuantityByLineId);
        /** @var numeric-string $totalWeight */
        $totalWeight = '0';
        foreach ($weights as $weight) {
            $totalWeight = bcadd($totalWeight, $weight, $working);
        }

        $lineCount = count(array_filter($landedQuantityByLineId, static fn (string $quantity): bool => bccomp($quantity, '0', QuantityScale::SCALE) > 0));

        // Select eligible lines before rounding; even tiny shares must retain
        // a final recipient. The LAST cost-bearing line absorbs the residual
        // (pool − Σ others) so the allocations sum to pool EXACTLY, with no
        // millième lost or gained to independent rounding.
        /** @var list<array{line: StockTransferLine, product: Product, allocated: numeric-string}> $allocations */
        $allocations = [];
        foreach ($transfer->lines as $line) {
            $eligible = bccomp($totalWeight, '0', $working) > 0
                ? bccomp($weights[$line->id], '0', $working) > 0
                : bccomp($landedQuantityByLineId[$line->id] ?? '0', '0', QuantityScale::SCALE) > 0;
            if (! $eligible) {
                continue;
            }
            $product = Product::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->findOrFail($line->product_id);

            if (bccomp($totalWeight, '0', $working) > 0) {
                $allocated = bcmul($pool, bcdiv($weights[$line->id], $totalWeight, $working), $working);
            } else {
                $allocated = bcdiv($pool, (string) $lineCount, $working);
            }

            $allocations[] = ['line' => $line, 'product' => $product, 'allocated' => $allocated];
        }

        $lastIndex = count($allocations) - 1;

        // Reconcile at the persisted scale (4), after currency-dependent working precision.
        // allocated_transfer_cost is stored at 4 dp and recordCostAdjustment
        // capitalizes the SAME value; reconciling the residual at 6 dp and then
        // truncating to 4 dp on persist loses a millième per line (e.g. 7 equal
        // lines of 100 -> 14.2857 x 7 = 99.9999 < 100). Every line EXCEPT the last
        // is formatted to 4 dp; the last absorbs (pool − Σ others) at 4 dp so
        // Σ persisted == pool EXACTLY at the stored scale.
        /** @var numeric-string $pool4dp */
        $pool4dp = CurrencyScale::bcformat($pool, QuantityScale::SCALE);
        /** @var numeric-string $runningSumOfOthers4dp */
        $runningSumOfOthers4dp = '0';

        foreach ($allocations as $index => $allocation) {
            $line = $allocation['line'];
            $product = $allocation['product'];

            $allocated4dp = $index === $lastIndex
                ? bcsub($pool4dp, $runningSumOfOthers4dp, QuantityScale::SCALE)
                : CurrencyScale::bcformat($allocation['allocated'], QuantityScale::SCALE);

            $runningSumOfOthers4dp = bcadd($runningSumOfOthers4dp, $allocated4dp, QuantityScale::SCALE);

            $line->allocated_transfer_cost = $allocated4dp;
            $line->save();

            if (bccomp($allocated4dp, '0', QuantityScale::SCALE) === 0) {
                continue;
            }

            $this->wacService->recordCostAdjustment(
                product: $product,
                // Pass the same 4-dp numeric-string persisted to the transfer
                // line so stored sum and capitalized sum both equal pool.
                additionalCost: $allocated4dp,
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
     * Return `$quantity` of `$line` to the transfer's SOURCE location as TransferIn
     * movements, lot-aware. Extracted from the cancel restock loop at
     * StockTransferService.php:451-480 and called by BOTH `cancel()` and the
     * `return_to_source` close disposition (seam 7).
     *
     * The caller has already acquired every line product's advisory lock in ONE
     * sorted call, and is inside its own transaction.
     *
     * @param  numeric-string  $quantity  the line total to restock
     * @param  array<int, numeric-string>  $lotQuantities  batch_id => quantity; MUST be empty
     *                                                     for a lot-less line and MUST sum to
     *                                                     $quantity for a lot-tracked one
     * @return array<int|string, string> created movement ids, keyed by batch_id for a
     *                                   lot-tracked line and by the single key '' for a
     *                                   lot-less one
     */
    public function restockAtSource(
        StockTransfer $transfer,
        StockTransferLine $line,
        string $quantity,
        array $lotQuantities,
        string $userId,
        string $reference,
    ): array {
        $movementIds = [];

        if ($lotQuantities !== []) {
            foreach ($lotQuantities as $batchId => $lotQuantity) {
                $movement = $this->stockAdjustmentService->receive(
                    productId: $line->product_id,
                    locationId: $transfer->source_location_id,
                    quantity: $lotQuantity,
                    reference: $reference,
                    userId: $userId,
                    batchId: $batchId,
                    expectedCompanyId: $transfer->company_id,
                    variantId: $line->variant_id,
                    movementType: MovementType::TransferIn,
                    transferId: $transfer->id,
                );

                $movementIds[$batchId] = (string) $movement->id;
            }

            return $movementIds;
        }

        $movement = $this->stockAdjustmentService->receive(
            productId: $line->product_id,
            locationId: $transfer->source_location_id,
            quantity: $quantity,
            reference: $reference,
            userId: $userId,
            expectedCompanyId: $transfer->company_id,
            variantId: $line->variant_id,
            movementType: MovementType::TransferIn,
            transferId: $transfer->id,
        );

        $movementIds[''] = (string) $movement->id;

        return $movementIds;
    }
}
