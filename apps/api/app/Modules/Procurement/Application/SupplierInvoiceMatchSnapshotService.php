<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\DocumentLine;
use App\Shared\Domain\CurrencyScale;

final readonly class SupplierInvoiceMatchSnapshotService
{
    public function __construct(
        private ReceiptLineConsumptionPlanner $receiptPlanner,
    ) {}

    /**
     * @return array{price_match_basis: numeric-string|null, matched_receipt_line_id: string|null}
     */
    public function forInvoiceLine(DocumentLine $invoiceLine, string $qtyAlreadyPlanned = '0.0000'): array
    {
        /** @var string|null $sourceLineId */
        $sourceLineId = $invoiceLine->source_line_id;
        if ($sourceLineId === null) {
            return ['price_match_basis' => null, 'matched_receipt_line_id' => null];
        }

        return $this->forSourceLine($sourceLineId, (string) $invoiceLine->quantity, $qtyAlreadyPlanned);
    }

    /**
     * @return array{price_match_basis: numeric-string|null, matched_receipt_line_id: string|null}
     */
    public function forSourceLine(string $sourceLineId, string $qty, string $qtyAlreadyPlanned = '0.0000'): array
    {
        /** @var DocumentLine|null $poLine */
        $poLine = DocumentLine::query()->find($sourceLineId);
        if ($poLine === null) {
            return ['price_match_basis' => null, 'matched_receipt_line_id' => null];
        }

        $slices = $this->receiptPlanner->plan($sourceLineId, $qty, $qtyAlreadyPlanned);
        if ($slices === []) {
            return [
                'price_match_basis' => CurrencyScale::bcformatStrict((string) ($poLine->accrual_unit_cost ?? $poLine->landed_unit_cost ?? $poLine->unit_price), 6),
                'matched_receipt_line_id' => null,
            ];
        }

        /** @var numeric-string $totalQty */
        $totalQty = '0.0000';
        /** @var numeric-string $totalValue */
        $totalValue = '0.0000000';
        foreach ($slices as $slice) {
            $totalQty = bcadd($totalQty, $slice['qty'], 4);
            $totalValue = bcadd($totalValue, bcmul($slice['qty'], $slice['basis'], 7), 7);
        }

        /** @var numeric-string $weighted */
        $weighted = bccomp($totalQty, '0', 4) > 0
            ? bcdiv($totalValue, $totalQty, 7)
            : (string) ($poLine->accrual_unit_cost ?? $poLine->landed_unit_cost ?? $poLine->unit_price);

        return [
            'price_match_basis' => CurrencyScale::bcformatStrict(CurrencyScale::bcround($weighted, 6), 6),
            'matched_receipt_line_id' => $slices[0]['receipt_line_id'],
        ];
    }
}
