<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Detector **D-f delivery-note arm** — a goods line that moved NO stock.
 *
 * POS and goods-receipt arms live in `CheckCogsCoverageCommand` because their
 * source tables and identity grains differ from documents. This scanner owns
 * only the confirmed-DN query and accepts the company cutover watermark.
 *
 * ── WHY THIS CHECK EXISTS AND NO MOVEMENT-KEYED CHECK CAN REPLACE IT ──
 * Every other detector in the lane keys on a `stock_movements` row: "this
 * movement should have had a journal entry / a cost / an attribution". This one
 * asks the prior question — *was a movement written at all?* — because the two
 * known silent skips produce a document that looks complete from every other
 * angle:
 *
 *   - `WeightedAverageCostService` degrades to a no-op when the product has no
 *     `stock_levels` row at that location: no movement, no exception;
 *   - `DeliveryNoteService::issueStock()` `continue`s a line whose location
 *     cannot be resolved (neither the line nor the document has one).
 *
 * In both cases the delivery note is Confirmed and sealed, the invoice posts,
 * revenue is recognised — and there is no movement, so post-cutover there is no
 * COGS either. Invisible by construction to anything that starts from a
 * movement.
 *
 * ── GRANULARITY ── Stock movements are keyed at DOCUMENT level
 * (`reference_type = 'Document'`, `reference_id = <document id>`), not at line
 * level, so this reports at `(document, product)` granularity: a physical
 * product line whose document produced no movement for that product at all.
 * Reporting per line would manufacture duplicates for a FEFO-split product.
 *
 * ── COST ── The candidate query is set-based and joined; the per-document work
 * is one `whereIn` over the products of that document. It is a nightly report,
 * not a request-path query.
 */
class UndeliveredGoodsLineScanner
{
    private const CHUNK = 200;

    /**
     * CONFIRMED DELIVERY NOTES carrying a physical product for which no stock
     * movement exists. POS and goods-receipt arms are routed to 3C — see the
     * class docblock.
     *
     * @return list<array{
     *     line_id: string,
     *     document_id: string,
     *     document_number: string,
     *     document_type: string,
     *     document_date: string,
     *     age_days: int,
     *     product_id: string,
     *     quantity: string
     * }>
     */
    public function scan(string $companyId, ?\DateTimeInterface $cutoverAt = null): array
    {
        $tenantId = Company::query()->whereKey($companyId)->value('tenant_id');
        if (! is_string($tenantId) || $tenantId === '') {
            return [];
        }

        // Documented SQL counterpart of PhysicalLinePredicate's scoped row
        // form; parity is pinned by
        // test_scanner_sql_physical_predicates_match_the_scoped_row_predicate.
        $physicalProductIds = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_physical', true)
            ->select('id');

        $findings = [];

        Document::query()
            ->where('company_id', $companyId)
            // ONE arm, stated as one arm. The nested closure that used to wrap
            // this read as though a second arm were coming is gone: it made the
            // query look like the docblock's claim rather than like the code.
            ->where('type', DocumentType::DeliveryNote)
            ->where('status', DocumentStatus::Confirmed)
            ->when($cutoverAt !== null, static fn (Builder $query): Builder => $query->where('created_at', '>=', $cutoverAt))
            ->whereHas('lines', function (Builder $lineQuery) use ($physicalProductIds): void {
                $lineQuery->whereNotNull('product_id')->whereIn('product_id', $physicalProductIds);
            })
            ->with(['lines' => function ($lineQuery) use ($physicalProductIds): void {
                $lineQuery->whereNotNull('product_id')->whereIn('product_id', $physicalProductIds);
            }])
            ->orderBy('document_date')
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($documents) use (&$findings, $companyId): void {
                foreach ($documents as $document) {
                    $productIds = $document->lines
                        ->pluck('product_id')
                        ->filter()
                        ->map(static fn (mixed $id): string => (string) $id)
                        ->unique()
                        ->values();

                    if ($productIds->isEmpty()) {
                        continue;
                    }

                    $movedProductIds = StockMovement::query()
                        ->where('company_id', $companyId)
                        ->where('reference_type', 'Document')
                        ->where('reference_id', $document->id)
                        ->whereIn('product_id', $productIds->all())
                        ->pluck('product_id')
                        ->map(static fn (mixed $id): string => (string) $id)
                        ->unique()
                        ->all();

                    foreach ($document->lines as $line) {
                        $productId = (string) $line->product_id;

                        if (in_array($productId, $movedProductIds, true)) {
                            continue;
                        }

                        $findings[] = [
                            'line_id' => (string) $line->id,
                            'document_id' => (string) $document->id,
                            'document_number' => (string) $document->document_number,
                            'document_type' => $document->type->value,
                            'document_date' => $document->document_date->toDateString(),
                            'age_days' => $document->created_at !== null
                                ? (int) floor($document->created_at->diffInDays(now(), true))
                                : 0,
                            'product_id' => $productId,
                            'quantity' => (string) $line->quantity,
                        ];

                        // One finding per (document, product): a FEFO-split
                        // product has many lines and one movement question.
                        $movedProductIds[] = $productId;
                    }
                }
            });

        return $findings;
    }
}
