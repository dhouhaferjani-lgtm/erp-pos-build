<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\ProportionalMoneyAllocator;

final class ReceiptBatchCostAllocator
{
    private const int QUANTITY_SCALE = 4;

    private const int COST_SCALE = 6;

    private const int WORKING_SCALE = 10;

    public function __construct(
        private readonly ProportionalMoneyAllocator $moneyAllocator,
    ) {}

    /**
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, string>  $receivedUnitPrices
     * @return array<string, numeric-string>
     */
    public function allocate(Document $purchaseOrder, array $receivedQuantities, array $receivedUnitPrices): array
    {
        $lineIds = [];
        $receivedValues = [];
        $pool = CurrencyScale::bcformatStrict('0', self::COST_SCALE);

        foreach ($purchaseOrder->lines as $line) {
            $lineId = (string) $line->id;
            /** @var numeric-string $qty */
            $qty = (string) ($receivedQuantities[$lineId] ?? '0.0000');

            if (bccomp($qty, '0.0000', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            /** @var numeric-string $orderedQty */
            $orderedQty = CurrencyScale::bcformatStrict((string) $line->quantity, self::QUANTITY_SCALE);
            /** @var numeric-string $allocatedCosts */
            $allocatedCosts = CurrencyScale::bcformatStrict((string) ($line->allocated_costs ?? '0'), self::COST_SCALE);

            if (bccomp($orderedQty, '0.0000', self::QUANTITY_SCALE) > 0 && bccomp($allocatedCosts, '0', self::COST_SCALE) > 0) {
                $fraction = bcdiv($qty, $orderedQty, self::WORKING_SCALE);
                $linePool = CurrencyScale::bcround(bcmul($allocatedCosts, $fraction, self::WORKING_SCALE), self::COST_SCALE);
                $pool = bcadd($pool, $linePool, self::COST_SCALE);
            }

            /** @var numeric-string $unitBasis */
            $unitBasis = (string) ($receivedUnitPrices[$lineId] ?? $line->unit_price);
            $receivedValue = bcmul($qty, $unitBasis, self::WORKING_SCALE);

            $lineIds[] = $lineId;
            $receivedValues[] = $receivedValue;
        }

        if ($lineIds === []) {
            return [];
        }

        /** @var array<int, numeric-string> $shares */
        $shares = $this->moneyAllocator->allocate($pool, $receivedValues, self::COST_SCALE, self::WORKING_SCALE);

        $result = [];
        foreach ($lineIds as $index => $lineId) {
            $result[$lineId] = $shares[$index] ?? CurrencyScale::bcformatStrict('0', self::COST_SCALE);
        }

        return $result;
    }
}
