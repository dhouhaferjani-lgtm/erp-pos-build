<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Adapters;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\StripSubToleranceDiscountsService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\DocumentTotalsCalculator;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Support\Carbon;

/**
 * Bridges WorkOrder transitions that produce a Document.
 *
 * Two distinct paths with STRICT fiscal-posting rules:
 *
 * - Quote (non-fiscal, called on Quoted transition): saves a Draft Document
 *   with WO lines mapped 1:1 to DocumentLines. Does NOT call the posting
 *   service and does NOT write to the fiscal hash chain. The quote remains
 *   unsigned / unnumbered in the fiscal sense. Returns the draft Document id.
 *
 * - Invoice (fiscal, called on Invoiced transition): creates Draft → confirms
 *   → invokes StripSubToleranceDiscountsService (Phase 4 anti-abuse, spec §7)
 *   → calls DocumentPostingService::post which signs + writes the fiscal hash
 *   chain entry. Returns the posted Document id.
 *
 * Both paths use the same WorkOrderLine → DocumentLine mapping, preserving the
 * `work_order_line_id` back-reference on each DocumentLine.
 */
final readonly class DocumentGenerationAdapter
{
    public function __construct(
        private DocumentNumberingService $numbering,
        private DocumentPostingService $posting,
        private WorkOrderLineRepositoryInterface $lines,
        private StripSubToleranceDiscountsService $discountStripper,
        private DocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * Generate a Quote document (Draft). Non-fiscal; does NOT hit the hash chain.
     *
     * @return string The new Document id.
     */
    public function generateQuote(WorkOrder $wo): string
    {
        $document = $this->buildDocument($wo, DocumentType::Quote);

        $this->mapLines($wo, $document);

        $this->totalsCalculator->recalculate($document);
        $document->save();

        return $document->id;
    }

    /**
     * Generate an Invoice document (Draft → Confirmed → Posted). The posting
     * service validates + signs + writes the fiscal hash chain entry.
     *
     * @return string The posted Document id.
     */
    public function generateInvoice(WorkOrder $wo): string
    {
        $document = $this->buildDocument($wo, DocumentType::Invoice);

        $this->mapLines($wo, $document);

        // M1 — Phase 4 anti-abuse strip (spec §7). The Workshop quote stage
        // legitimately bypasses the DiscountAboveTolerance request validator
        // (negotiation-stage), so a WO Quote may carry a sub-tolerance line
        // discount that must NOT survive into the fiscal hash chain on the
        // resulting Invoice. We invoke the strip BEFORE the totals recalc so
        // the resulting subtotal/tax/total reflect the post-strip state, and
        // BEFORE DocumentPostingService::post so the chain entry is signed
        // over the corrected document.
        //
        // Source identity: when a prior Quote document exists (the WO went
        // through the Quoted transition), pass it as `$source` so the audit
        // event records sourceType=quote / targetType=invoice. Otherwise fall
        // back to the invoice itself — the audit event still survives, with
        // sourceType=invoice signaling no prior Quote document was created.
        $sourceDocument = $wo->quote_document_id !== null
            ? Document::query()
                ->where('tenant_id', $wo->tenant_id)
                ->where('company_id', $wo->company_id)
                ->find($wo->quote_document_id)
            : null;
        $this->discountStripper->stripFromConvertedDocument(
            $sourceDocument ?? $document,
            $document,
        );

        $this->totalsCalculator->recalculate($document);
        $document->status = DocumentStatus::Confirmed;
        $document->save();

        $this->posting->post($document);

        return $document->id;
    }

    private function buildDocument(WorkOrder $wo, DocumentType $type): Document
    {
        $number = $this->numbering->generateNumber(
            tenantId: $wo->tenant_id,
            companyId: $wo->company_id,
            type: $type,
        );

        $document = new Document;
        $document->fill([
            'tenant_id' => $wo->tenant_id,
            'company_id' => $wo->company_id,
            'partner_id' => $wo->customer_partner_id,
            'vehicle_id' => $wo->vehicle_id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => Carbon::now(),
            'due_date' => null,
            'currency' => $wo->currency,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'notes' => null,
            'work_order_id' => $wo->id,
        ]);
        $document->save();

        return $document;
    }

    private function mapLines(WorkOrder $wo, Document $document): void
    {
        $workOrderLines = $this->lines->listForWorkOrder($wo->id);
        $lineNumber = 1;
        foreach ($workOrderLines as $wol) {
            // Skip informational bundle children — the bundle header line
            // carries the authoritative total.
            if ($wol->is_bundle_informational) {
                continue;
            }

            $this->mapLine($wol, $document, $lineNumber);
            $lineNumber++;
        }
    }

    private function mapLine(WorkOrderLine $wol, Document $document, int $lineNumber): void
    {
        // Derive an explicit discount_amount on the DocumentLine from the WO
        // line's discount_percent so StripSubToleranceDiscountsService — which
        // gates strictly on `discount_amount` — can act on it. Without this
        // derivation the percent silently vanishes into line_total and the
        // Phase 4 strip becomes a no-op for the WO path.
        /** @var numeric-string $quantity */
        $quantity = $wol->quantity;
        /** @var numeric-string $unitPrice */
        $unitPrice = $wol->unit_price;
        /** @var numeric-string $discountPercent */
        $discountPercent = $wol->discount_percent;

        /** @var numeric-string $discountAmount */
        $discountAmount = '0';
        if (bccomp($discountPercent, '0', 4) !== 0) {
            $lineSubtotal = bcmul($quantity, $unitPrice, 4);
            $discountAmount = bcdiv(
                bcmul($lineSubtotal, $discountPercent, 4),
                '100',
                4,
            );
        }

        $line = new DocumentLine;
        $line->fill([
            'document_id' => $document->id,
            'product_id' => $wol->product_id,
            'service_id' => $wol->service_id,
            'line_number' => $lineNumber,
            'description' => (string) $wol->display_name,
            'notes' => $wol->description,
            'designation_default_snapshot' => mb_substr((string) $wol->display_name, 0, 500),
            'quantity' => $wol->quantity,
            'quantity_delivered' => '0',
            'quantity_received' => '0',
            'unit_price' => $wol->unit_price,
            'discount_percent' => $wol->discount_percent,
            'discount_amount' => $discountAmount,
            'tax_rate' => $wol->tax_rate,
            'line_total' => $wol->line_total_incl_tax,
            'allocated_costs' => '0',
            'work_order_line_id' => $wol->id,
        ]);
        $line->save();
    }
}
