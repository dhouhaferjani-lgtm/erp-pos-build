<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Procurement\Domain\Dto\RfqPayload;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class PurchaseQuoteRequestToPurchaseOrderConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function __construct(
        protected readonly DocumentNumberingService $numberingService,
        protected readonly CurrencyScaleResolverInterface $scaleResolver,
        protected readonly TaxCalculationService $taxCalculationService,
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::PurchaseQuoteRequest;
    }

    public function targetType(): DocumentType
    {
        return DocumentType::PurchaseOrder;
    }

    public function canConvert(Document $source): bool
    {
        return $this->getConversionErrors($source) === [];
    }

    /**
     * @return list<string>
     */
    public function getConversionErrors(Document $source): array
    {
        $errors = [];

        if ($source->type !== DocumentType::PurchaseQuoteRequest) {
            $errors[] = 'Source document must be a purchase quote request';
        }

        if ($source->status !== DocumentStatus::Confirmed) {
            $errors[] = 'RFQ must have a recorded response before conversion';
        }

        if ($source->lines->isEmpty()) {
            $errors[] = 'RFQ must have at least one line item';
        }

        try {
            $payload = RfqPayload::fromArray($source->payload ?? []);
            if ($payload->responseRecordedAt === null) {
                $errors[] = 'RFQ must have a recorded response before conversion';
            }
        } catch (\InvalidArgumentException) {
            $errors[] = 'RFQ payload is missing group metadata';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Ticket 2026-08-03-w4-purchasing-inventory-defects.md #3 (MTP-RFQ-06):
     * RFQ lines can never carry an explicit tax_rate (CreatePurchaseQuoteRequestRequest /
     * UpdatePurchaseQuoteRequestRequest declare no such field), so copying
     * `$line->tax_rate` straight through always yielded NULL — and
     * PurchaseOrderService::confirmAndAllocateCosts computes tax FROM the line's
     * tax_rate, so a NULL rate silently meant zero VAT forever.
     *
     * This Domain-tier converter does NOT resolve tax rates itself (that would
     * inject the Application-tier DocumentLineTaxResolver into a Domain class —
     * a hexagonal-layer violation). The caller resolves rates using the same
     * default chain DraftPurchaseOrderService uses for the replenishment-sourcing
     * path (product tax_rate / tax configuration / company default) and passes
     * them in via `$options['tax_rates']`, keyed by RFQ line id — see
     * PurchaseQuoteRequestAwardService::resolveTaxRates() in the Application tier
     * (deliberately NOT an `@see` docblock tag — this Domain-tier class must not
     * carry even a docblock-only reference to an Application-tier class).
     *
     * @param  array<string, mixed>  $options  `tax_rates`: array<string RFQ-line-id, numeric-string>
     */
    public function convert(Document $source, array $options = []): Document
    {
        if ($source->type !== DocumentType::PurchaseQuoteRequest) {
            throw new \InvalidArgumentException('Source document must be a purchase quote request');
        }

        if (! $this->canConvert($source)) {
            throw new \DomainException(implode('; ', $this->getConversionErrors($source)), 422);
        }

        /** @var array<string, mixed> $rawTaxRates */
        $rawTaxRates = is_array($options['tax_rates'] ?? null) ? $options['tax_rates'] : [];

        $purchaseOrder = Document::create([
            'tenant_id' => $source->tenant_id,
            'company_id' => $source->company_id,
            'location_id' => $source->location_id,
            'partner_id' => $source->partner_id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => $source->fiscal_category,
            'fiscal_status' => $source->fiscal_status,
            'status' => DocumentStatus::Draft,
            'document_number' => $this->numberingService->generateNumber(
                $source->tenant_id,
                $source->company_id,
                DocumentType::PurchaseOrder,
            ),
            'document_date' => now()->toDateString(),
            'currency' => $source->currency,
            'subtotal' => $source->subtotal,
            'discount_amount' => $source->discount_amount,
            'tax_amount' => $source->tax_amount,
            'total' => $source->total,
            'balance_due' => $source->total,
            'notes' => $source->notes,
            'internal_notes' => $source->internal_notes,
            'reference' => $source->document_number,
            'source_document_id' => $source->id,
        ]);

        $scale = $source->currency !== ''
            ? $this->scaleResolver->getScale($source->currency)
            : $this->scaleResolver->getScaleSafe(null, 3);
        $working = $scale + 4;

        $lines = $source->loadMissing('lines')->lines->values();

        foreach ($lines as $line) {
            $rawRate = $rawTaxRates[$line->id] ?? $line->tax_rate;
            $taxRate = is_numeric($rawRate) ? CurrencyScale::bcformatStrict((string) $rawRate, 2) : null;

            $lineTotal = (string) ($line->line_total ?? '0');
            $taxAmount = CurrencyScale::bcformatStrict('0', $scale);
            if ($taxRate !== null && bccomp($taxRate, '0', 4) !== 0) { // precision-ok: percent-rate non-zero check (tax_rate is scale-2, never currency-scaled), not a money/quantity comparison
                $taxAmount = CurrencyScale::bcformatStrict(
                    bcmul($lineTotal, bcdiv($taxRate, '100', $working), $working),
                    $scale,
                );
            }

            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $purchaseOrder->id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'product_code' => $line->product_code,
                'service_id' => $line->service_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'tax_recoverable' => true,
                'recoverable_tax_amount' => $taxAmount,
                'non_recoverable_tax_amount' => CurrencyScale::bcformatStrict('0', $scale),
                'line_total' => $line->line_total,
                'notes' => $line->notes,
                'designation_default_snapshot' => $line->designation_default_snapshot,
                'source_line_id' => $line->id,
                'work_order_line_id' => $line->getAttribute('work_order_line_id'),
            ]);
        }

        // The awarded draft must show its OWN taxed totals — not the RFQ's
        // always-untaxed header copied verbatim (gate finding I-1/I-2). Also
        // repairs balance_due, which previously diverged from total the moment
        // confirm() updated tax_amount/total but not balance_due.
        $this->recalculateTotals($purchaseOrder);

        $this->dispatchConversionEvent($source, $purchaseOrder);

        return $purchaseOrder->refresh()->load('lines');
    }

    private function dispatchConversionEvent(Document $source, Document $target): void
    {
        $authId = Auth::id();
        $userId = $authId !== null ? (string) $authId : null;

        event(new DocumentConverted(
            sourceDocumentId: $source->id,
            targetDocumentId: $target->id,
            companyId: $source->company_id,
            tenantId: $source->tenant_id,
            sourceDocumentNumber: $source->document_number,
            targetDocumentNumber: $target->document_number,
            sourceType: $source->type->value,
            targetType: $target->type->value,
            userId: $userId,
            convertedAt: now()->toIso8601String(),
            isPartial: false,
            metadata: [],
        ));
    }
}
