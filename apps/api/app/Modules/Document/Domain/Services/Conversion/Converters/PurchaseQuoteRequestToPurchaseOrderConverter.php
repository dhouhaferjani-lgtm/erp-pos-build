<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\DocumentLineTaxResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Procurement\Domain\Dto\RfqPayload;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class PurchaseQuoteRequestToPurchaseOrderConverter implements DocumentConverterInterface
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentLineTaxResolver $lineTaxResolver,
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
     * @param  array<string, mixed>  $options
     */
    public function convert(Document $source, array $options = []): Document
    {
        if ($source->type !== DocumentType::PurchaseQuoteRequest) {
            throw new \InvalidArgumentException('Source document must be a purchase quote request');
        }

        if (! $this->canConvert($source)) {
            throw new \DomainException(implode('; ', $this->getConversionErrors($source)), 422);
        }

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

        $lines = $source->loadMissing('lines')->lines->values();
        $resolvedTaxRates = $this->resolveTaxRates($source, $lines);

        foreach ($lines as $index => $line) {
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
                'tax_rate' => $resolvedTaxRates[$index],
                'line_total' => $line->line_total,
                'notes' => $line->notes,
                'designation_default_snapshot' => $line->designation_default_snapshot,
                'source_line_id' => $line->id,
                'work_order_line_id' => $line->getAttribute('work_order_line_id'),
            ]);
        }

        $this->dispatchConversionEvent($source, $purchaseOrder);

        return $purchaseOrder->refresh()->load('lines');
    }

    /**
     * Resolve a tax rate for every RFQ line being carried into the PO.
     *
     * Ticket 2026-08-03-w4-purchasing-inventory-defects.md #3: RFQ lines can
     * never carry an explicit tax_rate (CreatePurchaseQuoteRequestRequest /
     * UpdatePurchaseQuoteRequestRequest declare no such field), so copying
     * `$line->tax_rate` straight through always yields NULL — and
     * PurchaseOrderService::confirmAndAllocateCosts computes tax FROM the
     * line's tax_rate, so a NULL rate silently means zero VAT forever. Resolve
     * the same default chain DraftPurchaseOrderService already uses for the
     * replenishment-sourcing path (product tax_rate / tax configuration /
     * company default) here at the conversion boundary.
     *
     * @param  Collection<int, DocumentLine>  $lines
     * @return list<numeric-string>
     */
    private function resolveTaxRates(Document $source, Collection $lines): array
    {
        $company = Company::query()->findOrFail($source->company_id);

        $productIds = $lines
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var Collection<array-key, Product> $products */
        $products = Product::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('company_id', $source->company_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $payloads = $lines->map(static function (DocumentLine $line): array {
            $payload = [
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => $line->tax_rate,
            ];

            if ($line->product_id !== null) {
                $payload['product_id'] = $line->product_id;
            }

            return $payload;
        })->values()->all();

        $resolved = $this->lineTaxResolver->resolve($payloads, $company, $products);

        $rates = [];
        foreach (array_values($resolved) as $payload) {
            $rate = (string) ($payload['tax_rate'] ?? '0.00');
            if (! is_numeric($rate)) {
                throw new \DomainException('Resolved tax rate must be numeric.');
            }
            $rates[] = $rate;
        }

        return $rates;
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
