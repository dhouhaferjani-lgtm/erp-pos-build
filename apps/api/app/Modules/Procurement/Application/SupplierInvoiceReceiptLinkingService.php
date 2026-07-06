<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SupplierInvoiceReceiptLinkingService
{
    public function __construct(
        private SupplierInvoiceMatchSnapshotService $matchSnapshotService,
        private SupplierInvoiceMatcher $matcher,
    ) {}

    /**
     * Links a pending supplier invoice at PO-line grain.
     *
     * The caller supplies a receipt_line_id for user ergonomics; that receipt line
     * resolves the authoritative PO line. The invoice line stores source_line_id
     * as the PO line id, and matched_receipt_line_id is re-derived by the match
     * snapshot planner from posted receipt-line availability.
     *
     * @param  list<array{invoice_line_id: string, receipt_line_id: string}>  $links
     */
    public function link(Document $supplierInvoice, array $links): Document
    {
        if ($links === []) {
            throw new \DomainException('At least one receipt line link is required.');
        }

        return DB::transaction(function () use ($supplierInvoice, $links): Document {
            $supplierInvoice->load('lines');
            if ($supplierInvoice->type !== DocumentType::SupplierInvoice || $supplierInvoice->status !== DocumentStatus::Draft) {
                throw new \DomainException('Only draft supplier invoices can link receipt lines.');
            }

            /** @var array<string, mixed> $payload */
            $payload = $supplierInvoice->payload ?? [];
            $supplierPayload = is_array($payload['supplier_invoice'] ?? null) ? $payload['supplier_invoice'] : [];
            if (($supplierPayload['pending_receipt'] ?? false) !== true) {
                throw new \DomainException('LINK_NOT_PENDING');
            }

            $lineIds = array_values(array_unique(array_map(
                static fn (array $link): string => $link['invoice_line_id'],
                $links,
            )));
            $receiptLineIds = array_values(array_unique(array_map(
                static fn (array $link): string => $link['receipt_line_id'],
                $links,
            )));

            /** @var Collection<int, DocumentLine> $invoiceLines */
            $invoiceLines = $supplierInvoice->lines
                ->whereIn('id', $lineIds)
                ->keyBy('id');
            if ($invoiceLines->count() !== count($lineIds)) {
                throw new \DomainException('One or more invoice lines do not belong to this supplier invoice.');
            }

            /** @var Collection<int, GoodsReceiptLine> $receiptLines */
            $receiptLines = GoodsReceiptLine::query()
                ->postedReceipts()
                ->where('company_id', $supplierInvoice->company_id)
                ->whereIn('id', $receiptLineIds)
                ->with(['poLine.document'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($receiptLines->count() !== count($receiptLineIds)) {
                throw new \DomainException('One or more receipt lines are not posted or do not belong to this company.');
            }

            /** @var array<string, string> $lineToPoLine */
            $lineToPoLine = [];
            /** @var list<string> $newSourceDocumentIds */
            $newSourceDocumentIds = [];

            foreach ($links as $link) {
                /** @var GoodsReceiptLine $receiptLine */
                $receiptLine = $receiptLines->get($link['receipt_line_id']);
                /** @var DocumentLine $invoiceLine */
                $invoiceLine = $invoiceLines->get($link['invoice_line_id']);
                $poLine = $receiptLine->poLine;
                $purchaseOrder = $poLine->document;
                if (
                    $purchaseOrder->type !== DocumentType::PurchaseOrder
                    || $purchaseOrder->company_id !== $supplierInvoice->company_id
                    || $purchaseOrder->partner_id !== $supplierInvoice->partner_id
                    || $purchaseOrder->currency !== $supplierInvoice->currency
                ) {
                    throw new \DomainException('Receipt line purchase order does not match supplier invoice company, supplier, and currency.');
                }

                if ($invoiceLine->product_id !== $poLine->product_id || $invoiceLine->variant_id !== $poLine->variant_id) {
                    throw new \DomainException('LINK_PRODUCT_MISMATCH');
                }

                $lineToPoLine[$link['invoice_line_id']] = $poLine->id;
                $newSourceDocumentIds[] = $purchaseOrder->id;
            }

            /** @var list<string> $existingSourceDocumentIds */
            $existingSourceDocumentIds = array_values(array_filter(
                $supplierPayload['source_document_ids'] ?? [],
                static fn (mixed $id): bool => is_string($id),
            ));
            $sourceDocumentIds = array_values(array_unique([...$existingSourceDocumentIds, ...$newSourceDocumentIds]));

            /** @var array<string, numeric-string> $plannedBySourceLine */
            $plannedBySourceLine = [];
            /** @var DocumentLine $line */
            foreach ($supplierInvoice->lines->sortBy('line_number') as $line) {
                if (isset($lineToPoLine[$line->id])) {
                    $line->source_line_id = $lineToPoLine[$line->id];
                }

                if ($line->source_line_id === null || (bool) ($line->is_bonus_line ?? false)) {
                    $line->price_match_basis = null;
                    $line->matched_receipt_line_id = null;
                    $line->save();

                    continue;
                }

                $alreadyPlanned = $plannedBySourceLine[$line->source_line_id] ?? '0.0000';
                $snapshot = $this->matchSnapshotService->forInvoiceLine($line, $alreadyPlanned);
                $line->price_match_basis = $snapshot['price_match_basis'];
                $line->matched_receipt_line_id = $snapshot['matched_receipt_line_id'];
                $line->save();

                /** @var numeric-string $nextPlanned */
                $nextPlanned = bcadd($alreadyPlanned, (string) $line->quantity, 4);
                $plannedBySourceLine[$line->source_line_id] = CurrencyScale::bcformatStrict($nextPlanned, 4);
            }

            $hasUnlinkedInvoiceLine = $supplierInvoice->lines->contains(
                static fn (DocumentLine $line): bool => $line->source_line_id === null && ! (bool) ($line->is_bonus_line ?? false)
            );

            $payload['supplier_invoice'] = [
                ...$supplierPayload,
                'source_document_ids' => $sourceDocumentIds,
                'pending_receipt' => $hasUnlinkedInvoiceLine,
            ];

            $supplierInvoice->source_document_id = $supplierInvoice->source_document_id ?? $sourceDocumentIds[0];
            $supplierInvoice->payload = $payload;
            $supplierInvoice->load('lines');
            $supplierInvoice->match_status = $this->matcher->match($supplierInvoice);
            $supplierInvoice->save();

            /** @var Document $fresh */
            $fresh = $supplierInvoice->fresh(['lines', 'partner', 'sourceDocument']);

            return $fresh;
        });
    }
}
