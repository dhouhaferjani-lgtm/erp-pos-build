<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SuggestionsData extends Data
{
    /**
     * @param  list<array{id: string, name: string, vat_number: string|null, matched_by: string, score: string}>  $supplierCandidates
     * @param  list<list<array{id: string, name: string, sku: string|null, barcode: string|null, requires_batch_tracking: bool, matched_by: string, score: string}>>  $productCandidates
     * @param  list<array{id: string, document_number: string, supplier_id: string, total: numeric-string|null}>  $purchaseOrderCandidates
     * @param  list<array{po_line_id: string, receipt_line_id: string, product_id: string, uninvoiced_quantity: numeric-string, unit_price: numeric-string|null}>  $receiptLineCandidates
     */
    public function __construct(
        public array $supplierCandidates,
        public array $productCandidates,
        public array $purchaseOrderCandidates,
        public array $receiptLineCandidates,
    ) {}

    /**
     * @return array{
     *   supplier_candidates: list<array{id: string, name: string, vat_number: string|null, matched_by: string, score: string}>,
     *   product_candidates: list<list<array{id: string, name: string, sku: string|null, barcode: string|null, requires_batch_tracking: bool, matched_by: string, score: string}>>,
     *   purchase_order_candidates: list<array{id: string, document_number: string, supplier_id: string, total: numeric-string|null}>,
     *   receipt_line_candidates: list<array{po_line_id: string, receipt_line_id: string, product_id: string, uninvoiced_quantity: numeric-string, unit_price: numeric-string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'supplier_candidates' => $this->supplierCandidates,
            'product_candidates' => $this->productCandidates,
            'purchase_order_candidates' => $this->purchaseOrderCandidates,
            'receipt_line_candidates' => $this->receiptLineCandidates,
        ];
    }
}
