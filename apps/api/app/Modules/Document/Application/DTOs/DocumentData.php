<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\Document;
use App\Shared\Domain\CurrencyScale;
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
        public string $partner_id,
        public ?string $partner_name,
        public ?string $partner_email,
        public readonly ?VehicleContextData $vehicle_context,
        public string $type,
        public string $fiscal_category,
        public string $fiscal_status,
        public string $status,
        public bool $is_sealed,
        public bool $is_fiscal,
        public string $document_number,
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
        public bool $goods_received,
        public array $delivery_note_ids,
        public array $invoice_ids,
        public array $lines,
        public array $payments,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Document $document, bool $includeLines = true, int $scale = 3): self
    {
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

        /** @var list<string> $deliveryNoteIds */
        $deliveryNoteIds = isset($payload['delivery_note_ids']) && is_array($payload['delivery_note_ids'])
            ? array_map('strval', $payload['delivery_note_ids'])
            : [];

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

        // Calculate balance and amount paid
        $total = $document->total !== null ? (float) $document->total : 0.0;
        $balanceDue = $document->balance_due !== null ? (float) $document->balance_due : $total;
        $amountPaid = $total - $balanceDue;

        // Get computed outstanding amount, payment status, and fulfillment status (SOURCE OF TRUTH for single document views)
        $outstandingAmount = $document->getOutstandingAmount();
        $paymentStatus = $document->getPaymentStatus()->value;
        $fulfillmentStatus = $document->getFulfillmentStatus()->value;

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
            document_number: $document->document_number,
            document_date: $document->document_date->toDateString(),
            due_date: $document->due_date?->toDateString(),
            valid_until: $document->valid_until?->toDateString(),
            currency: $document->currency,
            subtotal: $document->subtotal !== null ? CurrencyScale::bcformat($document->subtotal, $scale) : null,
            discount_amount: $document->discount_amount !== null ? CurrencyScale::bcformat($document->discount_amount, $scale) : null,
            tax_amount: $document->tax_amount !== null ? CurrencyScale::bcformat($document->tax_amount, $scale) : null,
            total: $document->total !== null ? CurrencyScale::bcformat($total, $scale) : null,
            balance_due: CurrencyScale::bcformat($balanceDue, $scale),
            outstanding_amount: $outstandingAmount,
            payment_status: $paymentStatus,
            fulfillment_status: $fulfillmentStatus,
            amount_paid: CurrencyScale::bcformat($amountPaid, $scale),
            amount_residual: CurrencyScale::bcformat($balanceDue, $scale),
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
            goods_received: $goodsReceived,
            delivery_note_ids: $deliveryNoteIds,
            invoice_ids: $invoiceIds,
            lines: $lines,
            payments: $payments,
            created_at: $document->created_at?->toIso8601String() ?? '',
            updated_at: $document->updated_at?->toIso8601String() ?? '',
        );
    }
}
