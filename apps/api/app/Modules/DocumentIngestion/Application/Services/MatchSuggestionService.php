<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\DocumentIngestion\Application\DTO\ExtractedFieldData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractedLineData;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\DTO\SuggestionsData;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\ReceiptLineConsumptionPlanner;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final readonly class MatchSuggestionService
{
    public function __construct(
        private ReceiptLineConsumptionPlanner $receiptLineConsumptionPlanner,
    ) {}

    public function suggest(DocumentIngestion $ingestion, ExtractionResultData $result): SuggestionsData
    {
        $supplierCandidates = $this->supplierCandidates($ingestion, $result);
        $productCandidates = array_map(
            fn (ExtractedLineData $line): array => $this->productCandidates($ingestion, $line),
            $result->lines,
        );
        $supplierId = $supplierCandidates[0]['id'] ?? null;

        return new SuggestionsData(
            supplierCandidates: $supplierCandidates,
            productCandidates: $productCandidates,
            purchaseOrderCandidates: is_string($supplierId) ? $this->purchaseOrderCandidates($ingestion, $supplierId) : [],
            receiptLineCandidates: is_string($supplierId) ? $this->receiptLineCandidates($ingestion, $supplierId) : [],
        );
    }

    /**
     * @return list<array{id: string, name: string, vat_number: string|null, matched_by: string, score: string}>
     */
    private function supplierCandidates(DocumentIngestion $ingestion, ExtractionResultData $result): array
    {
        $vatNumber = $this->fieldValue($result->supplier['vat_number'] ?? null);
        $normalizedVatNumber = $vatNumber === null ? null : $this->normalizeVatNumber($vatNumber);
        $name = $this->fieldValue($result->supplier['name'] ?? null);
        $normalizedName = $name === null ? null : $this->normalizeName($name);

        /** @var Collection<int, Partner> $suppliers */
        $suppliers = Partner::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->whereIn('type', [PartnerType::Supplier, PartnerType::Both])
            ->where('is_active', true)
            ->get();

        $candidates = [];
        foreach ($suppliers as $supplier) {
            $matchedBy = null;
            $score = null;

            if ($normalizedVatNumber !== null && $this->normalizeVatNumber((string) $supplier->vat_number) === $normalizedVatNumber) {
                $matchedBy = 'vat_number';
                $score = '1.000';
            } elseif ($normalizedName !== null && $this->normalizeName($supplier->name) === $normalizedName) {
                $matchedBy = 'normalized_name';
                $score = '0.900';
            } elseif ($name !== null && Str::contains(Str::lower($supplier->name), Str::lower($name))) {
                $matchedBy = 'name_like';
                $score = '0.500';
            }

            if ($matchedBy !== null) {
                $candidates[] = [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'vat_number' => $supplier->vat_number,
                    'matched_by' => $matchedBy,
                    'score' => $score,
                ];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => self::compareScore($b['score'], $a['score']));

        return array_slice($candidates, 0, 5);
    }

    /**
     * @return list<array{id: string, name: string, sku: string|null, barcode: string|null, requires_batch_tracking: bool, matched_by: string, score: numeric-string}>
     */
    private function productCandidates(DocumentIngestion $ingestion, ExtractedLineData $line): array
    {
        $supplierRef = $this->fieldValue($line->supplierRef);
        $description = $this->fieldValue($line->description);

        $candidates = [];
        if ($supplierRef !== null) {
            $candidates = array_merge(
                $candidates,
                $this->productRows($ingestion, static fn (Builder $query): Builder => $query->where('sku', $supplierRef), 'sku', '1.000'),
                $this->productRows($ingestion, static fn (Builder $query): Builder => $query->where('barcode', $supplierRef), 'barcode', '0.950'),
                $this->productRows(
                    $ingestion,
                    static fn (Builder $query): Builder => $query->where(
                        static fn (Builder $nested): Builder => $nested
                            ->whereJsonContains('oem_numbers', $supplierRef)
                            ->orWhereJsonContains('cross_references', [['reference' => $supplierRef]])
                    ),
                    'supplier_ref',
                    '0.850',
                ),
            );
        }

        if ($description !== null) {
            $like = '%'.$this->escapeLike(Str::lower($description)).'%';
            $candidates = array_merge(
                $candidates,
                $this->productRows(
                    $ingestion,
                    static fn (Builder $query): Builder => $query->whereRaw('LOWER(name) LIKE ?', [$like]),
                    'name_like',
                    '0.500',
                ),
            );
        }

        $candidates = $this->dedupeProductCandidates($candidates);
        usort($candidates, static fn (array $a, array $b): int => bccomp($b['score'], $a['score'], 3)); // precision-ok: rank score fixed at 3dp, not money/quantity

        return array_slice($candidates, 0, 5);
    }

    /**
     * @return list<array{id: string, document_number: string, supplier_id: string, total: numeric-string|null}>
     */
    private function purchaseOrderCandidates(DocumentIngestion $ingestion, string $supplierId): array
    {
        /** @var Collection<int, Document> $orders */
        $orders = Document::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->where('partner_id', $supplierId)
            ->where('type', DocumentType::PurchaseOrder)
            ->whereIn('status', [DocumentStatus::Draft, DocumentStatus::Confirmed, DocumentStatus::Received])
            ->latest('created_at')
            ->limit(5)
            ->get();

        return array_values($orders->map(static fn (Document $order): array => [
            'id' => $order->id,
            'document_number' => $order->document_number,
            'supplier_id' => $order->partner_id,
            'total' => $order->total,
        ])->all());
    }

    /**
     * @return list<array{po_line_id: string, receipt_line_id: string, product_id: string, uninvoiced_quantity: numeric-string, unit_price: numeric-string|null}>
     */
    private function receiptLineCandidates(DocumentIngestion $ingestion, string $supplierId): array
    {
        $purchaseOrderIds = Document::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->where('partner_id', $supplierId)
            ->where('type', DocumentType::PurchaseOrder)
            ->pluck('id');

        $receiptIds = GoodsReceipt::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->whereIn('purchase_order_id', $purchaseOrderIds)
            ->pluck('id');

        /** @var Collection<int, GoodsReceiptLine> $lines */
        $lines = GoodsReceiptLine::query()
            ->with(['poLine'])
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->postedReceipts()
            ->whereIn('goods_receipt_id', $receiptIds)
            ->whereColumn('received_qty', '>', 'quantity_invoiced')
            ->latest('created_at')
            ->limit(20)
            ->get();

        $candidates = [];
        foreach ($lines as $line) {
            $matchable = $this->receiptLineConsumptionPlanner->matchableQty($line);
            if (bccomp($matchable, '0', 4) <= 0) { // precision-ok: stock quantities are standardized at 4dp
                continue;
            }

            $candidates[] = [
                'po_line_id' => $line->po_line_id,
                'receipt_line_id' => $line->id,
                'product_id' => $line->product_id,
                'uninvoiced_quantity' => CurrencyScale::bcformatStrict($matchable, 4),
                'unit_price' => $this->numericOrNull($line->received_unit_price) ?? $this->purchaseOrderUnitPrice($line),
            ];
        }

        return array_slice($candidates, 0, 10);
    }

    /**
     * @return numeric-string|null
     */
    private function purchaseOrderUnitPrice(GoodsReceiptLine $line): ?string
    {
        $poLine = $line->poLine;

        return $this->numericOrNull($poLine->unit_price);
    }

    /**
     * @return numeric-string|null
     */
    private function numericOrNull(?string $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  callable(Builder<Product>): Builder<Product>  $predicate
     * @param  numeric-string  $score
     * @return list<array{id: string, name: string, sku: string|null, barcode: string|null, requires_batch_tracking: bool, matched_by: string, score: numeric-string}>
     */
    private function productRows(DocumentIngestion $ingestion, callable $predicate, string $matchedBy, string $score): array
    {
        /** @var Collection<int, Product> $products */
        $products = $predicate($this->productBaseQuery($ingestion))
            ->limit(5)
            ->get();

        return array_values($products->map(static fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'requires_batch_tracking' => $product->requires_batch_tracking,
            'matched_by' => $matchedBy,
            'score' => $score,
        ])->all());
    }

    /**
     * @return Builder<Product>
     */
    private function productBaseQuery(DocumentIngestion $ingestion): Builder
    {
        return Product::query()
            ->where('tenant_id', $ingestion->tenant_id)
            ->where('company_id', $ingestion->company_id)
            ->where('is_active', true);
    }

    /**
     * @param  list<array{id: string, name: string, sku: string|null, barcode: string|null, requires_batch_tracking: bool, matched_by: string, score: numeric-string}>  $candidates
     * @return list<array{id: string, name: string, sku: string|null, barcode: string|null, requires_batch_tracking: bool, matched_by: string, score: numeric-string}>
     */
    private function dedupeProductCandidates(array $candidates): array
    {
        $deduped = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            if (! isset($deduped[$id]) || self::compareScore($candidate['score'], $deduped[$id]['score']) > 0) {
                $deduped[$id] = $candidate;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     */
    private static function compareScore(string $left, string $right): int
    {
        return bccomp($left, $right, 3); // precision-ok: rank score fixed at 3dp, not money/quantity
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function normalizeName(string $name): string
    {
        $normalized = Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->replaceMatches('/\b(sarl|sa|sas|eurl|snc|ltd|llc|inc)\b/', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim();

        return $normalized->toString();
    }

    private function normalizeVatNumber(string $vatNumber): string
    {
        return Str::of($vatNumber)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '')
            ->toString();
    }

    private function fieldValue(?ExtractedFieldData $field): ?string
    {
        if ($field === null) {
            return null;
        }

        $value = trim($field->value);

        return $value === '' ? null : $value;
    }
}
