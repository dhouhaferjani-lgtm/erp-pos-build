<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\DTOs\TransferReconciliationData;
use App\Modules\Inventory\Application\DTOs\TransferReconciliationLineData;
use App\Modules\Inventory\Application\DTOs\TransferReconciliationLotData;
use App\Modules\Inventory\Application\DTOs\TransferReconciliationReceiptData;
use App\Modules\Inventory\Application\DTOs\TransferReconciliationSummaryData;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Shared\Domain\QuantityScale;

final class TransferReconciliationService
{
    /** Line-grain receiver metrics; close outcomes are never attributed to the closer. */
    public const string PATTERN_DETECTION_SQL = <<<'SQL'
WITH e AS (SELECT event_properties AS p FROM stored_events
           WHERE event_class = 'App\Modules\Inventory\Domain\Events\StockTransferReceiptLineRecordedV1'
             AND created_at >= now() - interval '90 days'),
postings AS (SELECT p->>'companyId' company_id, p->>'actorUserId' user_id, p->>'transferLineId' line_id,
                    (p->>'quantityDamaged')::numeric damaged FROM e WHERE p->>'actorRole' = 'receiver'),
receipts AS (SELECT company_id, user_id, line_id, COUNT(*) postings, SUM(damaged) damaged
             FROM postings GROUP BY 1, 2, 3),
closes AS (SELECT DISTINCT p->>'transferLineId' line_id
           FROM e WHERE p->>'actorRole' = 'closer'
             AND ((p->>'quantityWrittenOff')::numeric > 0 OR (p->>'quantityReturned')::numeric > 0)),
baseline AS (SELECT company_id, AVG((damaged > 0)::int) damage_rate FROM receipts GROUP BY 1)
SELECT r.company_id, r.user_id, COUNT(*) lines_posted, SUM(r.postings) postings,
       AVG((r.damaged > 0)::int) damage_rate, b.damage_rate company_damage_rate,
       COUNT(*) FILTER (WHERE c.line_id IS NOT NULL) lines_later_confirmed_short,
       bool_or((SELECT COUNT(*) FROM receipts r2 WHERE r2.line_id = r.line_id) > 1) shared_line
FROM receipts r JOIN baseline b USING (company_id) LEFT JOIN closes c USING (line_id)
WHERE r.company_id = :company_id GROUP BY 1, 2, b.damage_rate ORDER BY lines_later_confirmed_short DESC, damage_rate DESC;
SQL;

    public function build(StockTransfer $transfer): TransferReconciliationData
    {
        $transfer->loadMissing('sourceLocation', 'destinationLocation', 'lines.product.unitOfMeasure', 'lines.batchAllocations.batch', 'receipts.receivedBy', 'receipts.lines');
        $lines = [];
        $withDiscrepancy = 0;
        $totals = ['sent' => '0', 'received' => '0', 'damaged' => '0', 'written_off' => '0', 'returned' => '0', 'remaining' => '0'];
        foreach ($transfer->lines as $line) {
            $decimals = QuantityScale::decimalPlacesForUnit($line->product->unitOfMeasure?->decimal_places);
            $format = static function (string $q) use ($decimals): string {
                if (! is_numeric($q)) {
                    throw new \LogicException('Reconciliation quantities must be decimals.');
                }

                return QuantityScale::formatForUnit($q, $decimals);
            };
            $quantities = ['sent' => $line->quantity, 'received' => $line->quantity_received, 'damaged' => $line->quantity_damaged, 'written_off' => $line->quantity_written_off, 'returned' => $line->quantity_returned, 'remaining' => $line->remainingQuantity()];
            $loss = bcadd(bcadd($line->quantity_damaged, $line->quantity_written_off, QuantityScale::SCALE), $line->quantity_returned, QuantityScale::SCALE);
            if (bccomp($loss, '0', QuantityScale::SCALE) > 0) {
                $withDiscrepancy++;
            }
            foreach ($quantities as $name => $quantity) {
                $totals[$name] = bcadd($totals[$name], $quantity, QuantityScale::SCALE);
            }
            $reasons = [];
            $lots = [];
            foreach ($transfer->receipts as $receipt) {
                foreach ($receipt->lines as $posted) {
                    if ($posted->transfer_line_id === $line->id && $posted->discrepancy_reason !== null && ! in_array($posted->discrepancy_reason, $reasons, true)) {
                        $reasons[] = $posted->discrepancy_reason;
                    }
                }
            }
            foreach ($line->batchAllocations as $allocation) {
                $lots[] = new TransferReconciliationLotData(
                    batch_id: (string) $allocation->batch_id, batch_number: $allocation->batch->batch_number, expiry_date: $allocation->batch->expiry_date?->format('Y-m-d'),
                    sent: $format($allocation->quantity), received: $format($allocation->quantity_received), damaged: $format($allocation->quantity_damaged),
                    written_off: $format($allocation->quantity_written_off), returned: $format($allocation->quantity_returned), remaining: $format($allocation->remainingQuantity()),
                    variance: $format(bcsub($allocation->quantity_received, $allocation->quantity, QuantityScale::SCALE)),
                );
            }
            $lines[] = new TransferReconciliationLineData(
                transfer_line_id: $line->id, product_id: $line->product_id, product_name: $line->product->name, product_sku: $line->product->sku,
                variant_id: $line->variant_id, unit_decimal_places: $decimals, is_lot_tracked: $line->batchAllocations->isNotEmpty(),
                sent: $format($line->quantity), received: $format($line->quantity_received), damaged: $format($line->quantity_damaged),
                written_off: $format($line->quantity_written_off), returned: $format($line->quantity_returned), remaining: $format($line->remainingQuantity()),
                variance: $format(bcsub($line->quantity_received, $line->quantity, QuantityScale::SCALE)), discrepancy_reasons: $reasons, lots: $lots,
            );
        }
        $receipts = [];
        foreach ($transfer->receipts as $receipt) {
            $receipts[] = TransferReconciliationReceiptData::fromModel($receipt);
        }

        return new TransferReconciliationData(
            transfer_id: $transfer->id, transfer_number: $transfer->transfer_number, status: $transfer->status,
            source_location_id: $transfer->source_location_id, source_location_name: $transfer->sourceLocation->name,
            destination_location_id: $transfer->destination_location_id, destination_location_name: $transfer->destinationLocation->name,
            initiated_at: $transfer->initiated_at?->toIso8601String() ?? '', closed_at: $transfer->closed_at?->toIso8601String(),
            close_disposition: $transfer->close_disposition, close_reason: $transfer->close_reason, close_note: $transfer->close_note,
            lines: $lines, receipts: $receipts,
            summary: new TransferReconciliationSummaryData(count($lines), $withDiscrepancy, $totals['sent'], $totals['received'], $totals['damaged'], $totals['written_off'], $totals['returned'], $totals['remaining'], $transfer->freight_uncapitalized),
        );
    }
}
