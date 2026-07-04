<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Shared\Domain\CurrencyScale;

final class ReceiptLineConsumptionPlanner
{
    /**
     * Pure FIFO planner; no writes.
     *
     * @return list<array{receipt_line_id: string, qty: numeric-string, basis: numeric-string}>
     */
    public function plan(string $poLineId, string $qtyToConsume): array
    {
        /** @var numeric-string $remaining */
        $remaining = CurrencyScale::bcformatStrict($qtyToConsume, 4);
        if (bccomp($remaining, '0', 4) <= 0) {
            return [];
        }

        $slices = [];

        /** @var GoodsReceiptLine $line */
        foreach (GoodsReceiptLine::query()
            ->where('po_line_id', $poLineId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get() as $line) {
            /** @var numeric-string $matchable */
            $matchable = $this->matchableQty($line);
            if (bccomp($matchable, '0', 4) <= 0) {
                continue;
            }

            /** @var numeric-string $qty */
            $qty = bccomp($matchable, $remaining, 4) > 0 ? $remaining : $matchable;
            $slices[] = [
                'receipt_line_id' => $line->id,
                'qty' => CurrencyScale::bcformatStrict($qty, 4),
                'basis' => CurrencyScale::bcformatStrict((string) $line->accrual_unit_cost, 6),
            ];

            /** @var numeric-string $remaining */
            $remaining = bcsub($remaining, $qty, 4);
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
        }

        return $slices;
    }

    /**
     * matchableQty = received_qty - quantity_invoiced (scale 4).
     *
     * @return numeric-string
     */
    public function matchableQty(GoodsReceiptLine $line): string
    {
        /** @var numeric-string $matchable */
        $matchable = bcsub((string) $line->received_qty, (string) $line->quantity_invoiced, 4);

        return CurrencyScale::bcformatStrict($matchable, 4);
    }

    /**
     * freeMatchableQty = free_qty - free_quantity_invoiced (scale 4).
     *
     * @return numeric-string
     */
    public function freeMatchableQty(GoodsReceiptLine $line): string
    {
        /** @var numeric-string $matchable */
        $matchable = bcsub((string) $line->free_qty, (string) ($line->free_quantity_invoiced ?? '0'), 4);

        return CurrencyScale::bcformatStrict($matchable, 4);
    }
}
