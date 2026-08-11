<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DeliveredQuantityResolver;
use App\Modules\Inventory\Application\DTOs\ReturnCostBasis;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\Log;

/**
 * Attributes a return-note line to the original DN exit movements. POS returns
 * deliberately do not use this resolver: their references are pos_receipt rows
 * and the POS projector restores from its own movement-cost snapshot.
 */
final class ReturnCostBasisResolver
{
    private const COST_SCALE = 6;

    private const QUANTITY_SCALE = 4;

    private const WORKING_SCALE = self::COST_SCALE + self::QUANTITY_SCALE;

    public function __construct(private readonly DeliveredQuantityResolver $deliveredQuantityResolver) {}

    public function resolveForReturnLine(DocumentLine $line, string $returnedQty): ReturnCostBasis
    {
        $line->loadMissing(['document', 'product']);
        $sourceId = $line->document->source_document_id;
        $source = $sourceId !== null
            ? Document::query()->where('company_id', $line->document->company_id)->find($sourceId)
            : null;

        $deliveryNoteIds = [];
        if ($source !== null && $source->type === DocumentType::Invoice) {
            $deliveryNoteIds = array_map(
                static fn (Document $note): string => $note->id,
                $this->deliveredQuantityResolver->confirmedDeliveryNotesFor($source),
            );
        } elseif ($source !== null && $source->type === DocumentType::DeliveryNote) {
            $deliveryNoteIds = [$source->id];
        }

        $candidates = $deliveryNoteIds === [] || $line->product_id === null
            ? collect()
            : StockMovement::query()
                ->where('company_id', $line->document->company_id)
                ->where('product_id', $line->product_id)
                ->where('reason', MovementReason::Delivery)
                ->where('reference_type', StockMovementReferenceType::Document->value)
                ->whereIn('reference_id', $deliveryNoteIds)
                ->whereNotNull('unit_cost')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get();

        $remaining = CurrencyScale::bcformatStrict($returnedQty, self::QUANTITY_SCALE);
        $drawnTotal = '0.0000';
        $accumulated = '0.0000000000';
        $movementIds = [];

        foreach ($candidates as $movement) {
            if (bccomp($remaining, '0', self::QUANTITY_SCALE) <= 0) {
                break;
            }

            $available = $movement->absoluteDeltaForRow();
            $drawn = bccomp($available, $remaining, self::QUANTITY_SCALE) < 0 ? $available : $remaining;
            if (bccomp($drawn, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            $unitCost = CurrencyScale::bcformatStrict((string) $movement->unit_cost, self::COST_SCALE);
            $accumulated = bcadd(
                $accumulated,
                bcmul($unitCost, $drawn, self::WORKING_SCALE),
                self::WORKING_SCALE,
            );
            $drawnTotal = bcadd($drawnTotal, $drawn, self::QUANTITY_SCALE);
            $remaining = bcsub($remaining, $drawn, self::QUANTITY_SCALE);
            $movementIds[] = $movement->id;
        }

        if (bccomp($drawnTotal, '0', self::QUANTITY_SCALE) > 0) {
            return new ReturnCostBasis(
                unitCost: CurrencyScale::bcround(
                    bcdiv($accumulated, $drawnTotal, self::WORKING_SCALE),
                    self::COST_SCALE,
                ),
                source: ReturnCostBasis::SOURCE_CURRENT_COST,
                movementIds: $movementIds,
            );
        }

        $fallback = CurrencyScale::bcformatStrict((string) ($line->product->cost_price ?? '0'), self::COST_SCALE);
        Log::warning('Return cost basis fell back to current product cost; no attributable delivery exit exists.', [
            'return_line_id' => $line->id,
            'product_id' => $line->product_id,
        ]);

        return new ReturnCostBasis($fallback, ReturnCostBasis::SOURCE_CURRENT_COST, []);
    }
}
