<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Application\Services\ProformaOutputPolicy;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DocumentData extends Data
{
    /**
     * @param  list<DocumentLineData>  $lines
     * @param  list<array{id: string, payment_id: string, amount: string, payment_date: string, payment_reference: string|null, payment_method: string|null}>  $payments
     * @param  list<string>  $delivery_note_ids
     * @param  list<string>  $invoice_ids
     */
    public function __construct(
        public string $id,
        public string $tenant_id,
        public ?string $partner_id,
        public ?string $partner_name,
        public ?string $partner_email,
        public readonly ?VehicleContextData $vehicle_context,
        public string $type,
        public string $fiscal_category,
        public string $fiscal_status,
        public string $status,
        public bool $is_sealed,
        public bool $is_fiscal,
        /**
         * C-F0w / SPEC §2.4 — whether a RENDERING of this document is a proforma
         * (no VAT, no rate rows, no seal wording, an ESTIMATED total).
         *
         * THE PREDICATE, NEVER ITS SYMPTOMS. This is the same answer the PDF
         * gets — {@see Document::isProformaOutput()},
         * which {@see ProformaOutputPolicy}
         * delegates to. The web used to guess from `status`
         * (`CreditNoteDetail.tsx:74-78` conceded it "cannot tell"), which is wrong
         * in both directions: a `Paid`-but-never-sealed invoice is a proforma and a
         * sealed-then-cancelled one is not. Never re-derive this on the client.
         */
        public bool $is_proforma,
        public ?string $document_number,
        public string $document_date,
        public ?string $due_date,
        public ?string $valid_until,
        public string $currency,
        public ?string $subtotal,
        public ?string $discount_amount,
        public ?string $tax_amount,
        public ?string $total,
        public ?string $balance_due,
        public ?string $outstanding_amount,
        public ?string $payment_status,
        public ?string $fulfillment_status,
        public ?string $amount_paid,
        public ?string $amount_residual,
        public ?string $notes,
        public ?string $internal_notes,
        public ?string $reference,
        public ?string $external_document_number,
        public ?string $external_document_date,
        public ?string $source_document_id,
        public ?string $source_document_number,
        public ?string $source_document_type,
        public ?string $converted_to_order_id,
        public ?string $converted_at,
        public bool $fully_delivered,
        public bool $fully_invoiced,
        public ?string $invoiced_at,
        public ?string $invoiced_by_document_id,
        public ?string $invoiced_by_document_number,
        public ?string $invoiced_via,
        public bool $goods_received,
        public bool $is_auto_generated,
        public array $delivery_note_ids,
        public array $invoice_ids,
        public ?ReturnDecisionProjection $return_decision,
        public int $return_decision_count,
        public array $lines,
        public array $payments,
        /**
         * The VAT-free figures a proforma renders — gross line amounts, the two
         * rows that make the box close, and the estimated total. Non-null exactly
         * when `is_proforma` is true AND the endpoint asked for it; a detail
         * endpoint always does. A page must gate its VAT rendering on
         * `is_proforma`, never on this field being present, so that an endpoint
         * which does not build the projection can only ever cost the reader some
         * rows — never leak a tax figure.
         */
        public ?ProformaPresentationData $proforma,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(
        Document $document,
        bool $includeLines = true,
        int $scale = 3,
        ?ProformaPresentationData $proforma = null,
    ): self {
        $lines = [];
        if ($includeLines) {
            // Ensure the product unit chain is loaded so each line's
            // quantity_decimals reflects the real unit precision rather than the
            // fallback. Single batched eager-load (NOT N+1); callers that already
            // eager-loaded it (e.g. via HandlesDocuments relation lists) pay
            // nothing. This covers post/convert/goods-receipt response paths that
            // refresh with fresh(['lines']) only.
            if (! $document->relationLoaded('lines')
                || ($document->lines->isNotEmpty() && ! $document->lines->first()->relationLoaded('product'))) {
                $document->load('lines.product.unitOfMeasure');
            }

            foreach ($document->lines as $line) {
                $lines[] = DocumentLineData::fromModel($line, $scale);
            }
        }

        // Build payments array from allocations.
        // Skip tolerance-only writeoff allocations (payment_id IS NULL) — they are not
        // real money movements and do not belong in the payment-history view; the audit
        // log + journal entry are the authoritative trail for those.
        $payments = [];
        if ($document->relationLoaded('allocations')) {
            foreach ($document->allocations as $allocation) {
                $payment = $allocation->payment;
                if ($payment === null) {
                    continue;
                }
                $payments[] = [
                    'id' => $allocation->id,
                    // Narrowed: $payment !== null implies payment_id is set; cast for PHPStan.
                    'payment_id' => (string) $allocation->payment_id,
                    'amount' => CurrencyScale::bcformat($allocation->amount, $scale),
                    'payment_date' => $payment->payment_date->toDateString(),
                    'payment_reference' => $payment->reference,
                    'payment_method' => $payment->paymentMethod->name ?? null,
                ];
            }
        }

        // Load partner if not already loaded
        $partner = $document->relationLoaded('partner') ? $document->partner : $document->partner()->first();

        // Get vehicle context if exists
        $vehicleContext = null;
        if ($document->relationLoaded('vehicleContext') && $document->vehicleContext !== null) {
            $vehicleContext = VehicleContextData::fromModel($document->vehicleContext);
        }

        // Extract conversion info from payload
        $payload = $document->payload ?? [];
        $convertedToOrderId = isset($payload['converted_to_order_id']) ? (string) $payload['converted_to_order_id'] : null;
        $convertedAt = isset($payload['converted_at']) ? (string) $payload['converted_at'] : null;

        // Extract delivery and invoice status from payload
        $fullyDelivered = (bool) ($payload['fully_delivered'] ?? false);
        $fullyInvoiced = (bool) ($payload['fully_invoiced'] ?? false);
        $goodsReceived = (bool) ($payload['goods_received'] ?? false);
        $isAutoGenerated = is_array($payload['auto_generated'] ?? null);
        $billingState = DeliveryNoteBillingState::fromPayload($payload);

        /** @var list<string> $deliveryNoteIds */
        $deliveryNoteIds = isset($payload['delivery_note_ids']) && is_array($payload['delivery_note_ids'])
            ? array_map('strval', $payload['delivery_note_ids'])
            : [];

        // Plan CF T16 / CF-D5. Without this the owner's "the choice is recorded"
        // exists only in the database: a cancelled invoice's detail page could not say
        // WHAT was decided about the goods or link to the return note it produced.
        //
        // Follows the `delivery_note_ids` precedent immediately below — this DTO emits
        // payload-DERIVED projections but never the raw `payload`, so the audit list is
        // narrowed to the decision that TOOK EFFECT plus a count. Rejected entries stay
        // internal: they are an audit trail for support, not something a document view
        // should present as state.
        $returnDecisions = is_array($payload['return_decisions'] ?? null) ? $payload['return_decisions'] : [];
        $returnDecision = null;
        foreach (array_reverse($returnDecisions) as $entry) {
            if (! is_array($entry) || ($entry['accepted'] ?? false) !== true) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $returnDecision = ReturnDecisionProjection::fromAuditRecord($entry);
            break;
        }

        /** @var list<string> $invoiceIds */
        $invoiceIds = isset($payload['invoice_ids']) && is_array($payload['invoice_ids'])
            ? array_map('strval', $payload['invoice_ids'])
            : [];

        // Get source document info for navigation back.
        // api.document.004: defense-in-depth — even if a corrupted row carries
        // a cross-tenant source_document_id, the lookup MUST refuse to surface
        // the foreign document's number/type. Scope by the parent document's
        // own tenant + company.
        $sourceDocument = null;
        if ($document->source_document_id !== null) {
            $sourceDocument = Document::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('company_id', $document->company_id)
                ->find($document->source_document_id);
        }

        // `documents.payload` is free-form JSONB, so `payload.invoice_id` is an
        // arbitrary legacy value — NOT a validated UUID. Binding it straight into the
        // `documents.id` PostgreSQL `uuid` key raises 22P02 (a 500 that also poisons
        // the surrounding transaction with 25P02), and the M1C backfill
        // (`2026_08_18_000002_create_delivery_note_billing_marks_table.php`) proves the
        // dirty shape exists in the field: its `safeInvoiceId()` counts such rows as
        // `unparseable_invoice_id`, neutralises the MARKER, and deliberately leaves the
        // payload untouched.
        //
        // This is the FORMAT half of the migration's contract, not the whole of it.
        // `safeInvoiceId()` guards `is_string()` FIRST and only then `trim`/`Str::isUuid`;
        // the `is_string()` half lives one layer up, in
        // `DeliveryNoteBillingState::fromPayload()`, because a non-string value 500s in
        // the CAST before it could ever reach this line. Neither layer reproduces the
        // migration's semantics alone — read them together.
        //
        // Effect of the pair: an unparseable id resolves to no invoicing document, while
        // `invoiced_at` and the lane below stay intact so the row still reads as
        // billed-but-unresolved rather than 500ing the whole delivery-note list.
        // (M5-terminal treasury F-1 / tenancy-authz F-T1, completed in r2 by
        // treasury `R2-1` == tenancy `F-R2-1`.)
        $invoicingDocument = null;
        if ($billingState->invoice_id !== null && Str::isUuid($billingState->invoice_id)) {
            $invoicingDocument = Document::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('company_id', $document->company_id)
                ->find($billingState->invoice_id);
        }

        // Get computed outstanding amount, payment status, and fulfillment status (SOURCE OF TRUTH for single document views)
        $outstandingAmount = $document->getOutstandingAmount();
        $paymentStatus = $document->getPaymentStatus()->value;
        $fulfillmentStatus = $document->getFulfillmentStatus()->value;

        $total = $document->total !== null
            ? CurrencyScale::bcformatStrict($document->total, $scale)
            : CurrencyScale::bcformatStrict('0', $scale);

        $isPaymentTrackedDocument = in_array($document->type, [
            DocumentType::Invoice,
            DocumentType::SalesOrder,
            DocumentType::PurchaseOrder,
        ], true);

        $balanceDue = $isPaymentTrackedDocument
            ? CurrencyScale::bcformatStrict($outstandingAmount, $scale)
            : ($document->balance_due !== null
                ? CurrencyScale::bcformatStrict($document->balance_due, $scale)
                : $total);

        $amountPaid = CurrencyScale::bcformatStrict(bcsub($total, $balanceDue, $scale), $scale);

        return new self(
            id: $document->id,
            tenant_id: $document->tenant_id,
            partner_id: $document->partner_id,
            partner_name: $partner?->name,
            partner_email: $partner?->email,
            vehicle_context: $vehicleContext,
            type: $document->type->value,
            fiscal_category: $document->fiscal_category->value,
            fiscal_status: $document->fiscal_status->value,
            status: $document->status->value,
            is_sealed: $document->isSealed(),
            is_fiscal: $document->isFiscal(),
            is_proforma: $document->isProformaOutput(),
            document_number: $document->document_number,
            document_date: $document->document_date->toDateString(),
            due_date: $document->due_date?->toDateString(),
            valid_until: $document->valid_until?->toDateString(),
            currency: $document->currency,
            subtotal: $document->subtotal !== null ? CurrencyScale::bcformat($document->subtotal, $scale) : null,
            discount_amount: $document->discount_amount !== null ? CurrencyScale::bcformat($document->discount_amount, $scale) : null,
            tax_amount: $document->tax_amount !== null ? CurrencyScale::bcformat($document->tax_amount, $scale) : null,
            total: $document->total !== null ? $total : null,
            balance_due: $balanceDue,
            outstanding_amount: $outstandingAmount,
            payment_status: $paymentStatus,
            fulfillment_status: $fulfillmentStatus,
            amount_paid: $amountPaid,
            amount_residual: $balanceDue,
            notes: $document->notes,
            internal_notes: $document->internal_notes,
            reference: $document->reference,
            external_document_number: $document->external_document_number,
            external_document_date: $document->external_document_date?->toDateString(),
            source_document_id: $document->source_document_id,
            source_document_number: $sourceDocument?->document_number,
            source_document_type: $sourceDocument?->type->value,
            converted_to_order_id: $convertedToOrderId,
            converted_at: $convertedAt,
            fully_delivered: $fullyDelivered,
            fully_invoiced: $fullyInvoiced,
            invoiced_at: $billingState->invoiced_at,
            invoiced_by_document_id: $billingState->invoice_id,
            invoiced_by_document_number: $invoicingDocument?->document_number,
            invoiced_via: $billingState->invoiced_via?->value,
            goods_received: $goodsReceived,
            is_auto_generated: $isAutoGenerated,
            delivery_note_ids: $deliveryNoteIds,
            invoice_ids: $invoiceIds,
            return_decision: $returnDecision,
            return_decision_count: count($returnDecisions),
            lines: $lines,
            payments: $payments,
            proforma: $document->isProformaOutput() ? $proforma : null,
            created_at: $document->created_at?->toIso8601String() ?? '',
            updated_at: $document->updated_at?->toIso8601String() ?? '',
        );
    }
}
