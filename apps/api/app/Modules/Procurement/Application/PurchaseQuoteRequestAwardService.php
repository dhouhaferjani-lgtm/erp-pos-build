<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\DocumentLineTaxResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Procurement\Domain\Dto\RfqPayload;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class PurchaseQuoteRequestAwardService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentConverterRegistry $converterRegistry,
        private readonly DocumentLineTaxResolver $lineTaxResolver,
    ) {}

    public function award(string $rfqId, string $tenantId, string $companyId): Document
    {
        return $this->db->transaction(function () use ($rfqId, $tenantId, $companyId): Document {
            /** @var Document $winner */
            $winner = Document::query()
                ->whereKey($rfqId)
                ->where('type', DocumentType::PurchaseQuoteRequest)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->firstOrFail();

            $groupId = RfqPayload::fromArray($winner->payload ?? [])->groupId;

            /** @var EloquentCollection<int, Document> $siblings */
            $siblings = Document::query()
                ->where('type', DocumentType::PurchaseQuoteRequest)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereRaw("payload->'rfq'->>'group_id' = ?", [$groupId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $lockedWinner = $siblings->firstWhere('id', $winner->id);
            if (! $lockedWinner instanceof Document) {
                throw new \DomainException('RFQ_GROUP_MEMBER_MISSING', 422);
            }

            $this->assertNoLiveAwardedPo($siblings);
            $this->assertResponded($lockedWinner);

            $lockedWinner->loadMissing('lines');

            $purchaseOrder = $this->converterRegistry->convert(
                $lockedWinner,
                DocumentType::PurchaseOrder,
                ['tax_rates' => $this->resolveTaxRates($lockedWinner, $tenantId, $companyId)],
            );

            foreach ($siblings as $sibling) {
                if ($sibling->id === $lockedWinner->id) {
                    continue;
                }

                $payload = RfqPayload::fromArray($sibling->payload ?? []);
                $sibling->status = DocumentStatus::Cancelled;
                $sibling->payload = (new RfqPayload(
                    groupId: $payload->groupId,
                    validityDate: $payload->validityDate,
                    supplierReference: $payload->supplierReference,
                    leadTimeDays: $payload->leadTimeDays,
                    responseRecordedAt: $payload->responseRecordedAt,
                    sentAt: $payload->sentAt,
                    closedReason: 'lost',
                ))->toArray();
                $sibling->save();
            }

            return $purchaseOrder;
        });
    }

    /**
     * Resolve a tax rate for every RFQ line being carried into the PO.
     *
     * Ticket 2026-08-03-w4-purchasing-inventory-defects.md #3 (MTP-RFQ-06): RFQ
     * lines can never carry an explicit tax_rate (CreatePurchaseQuoteRequestRequest
     * / UpdatePurchaseQuoteRequestRequest declare no such field), so an
     * RFQ-awarded PO used to carry NO tax rate and silently zero VAT. Resolved
     * HERE (Application tier), not inside the Domain-tier converter, so the
     * converter never has to depend on this Application-tier resolver — gate
     * finding I-4 (a Domain -> Application hexagonal violation). This is the
     * same default chain (product tax_rate / tax configuration / company
     * default) DraftPurchaseOrderService already uses for the sibling
     * replenishment-sourcing path.
     *
     * @return array<string, numeric-string> keyed by RFQ line id
     */
    private function resolveTaxRates(Document $rfq, string $tenantId, string $companyId): array
    {
        // Tenant-scoped lookup (mirrors DraftPurchaseOrderService::createDraft())
        // rather than a bare findOrFail — gate finding M-2.
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($companyId)
            ->firstOrFail();

        $lines = $rfq->lines;

        $productIds = $lines
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var EloquentCollection<array-key, Product> $products */
        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lineIds = [];
        $payloads = [];
        foreach ($lines as $line) {
            $payload = [
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => $line->tax_rate,
            ];

            if ($line->product_id !== null) {
                $payload['product_id'] = $line->product_id;
            }

            $lineIds[] = $line->id;
            $payloads[] = $payload;
        }

        $resolved = $this->lineTaxResolver->resolve($payloads, $company, $products);

        $rates = [];
        foreach (array_values($resolved) as $index => $payload) {
            $rate = (string) ($payload['tax_rate'] ?? '0.00');
            if (! is_numeric($rate)) {
                throw new \DomainException('Resolved tax rate must be numeric.');
            }
            $rates[$lineIds[$index]] = $rate;
        }

        return $rates;
    }

    /**
     * @param  EloquentCollection<int, Document>  $siblings
     */
    private function assertNoLiveAwardedPo(EloquentCollection $siblings): void
    {
        $siblingIds = $siblings->pluck('id')->all();

        $alreadyAwarded = Document::query()
            ->where('type', DocumentType::PurchaseOrder)
            ->where('tenant_id', $siblings->first()?->tenant_id)
            ->where('company_id', $siblings->first()?->company_id)
            ->whereIn('source_document_id', $siblingIds)
            ->where('status', '!=', DocumentStatus::Cancelled)
            ->exists();

        if ($alreadyAwarded) {
            throw new \DomainException('RFQ_GROUP_ALREADY_AWARDED', 422);
        }
    }

    private function assertResponded(Document $winner): void
    {
        $payload = RfqPayload::fromArray($winner->payload ?? []);

        if ($winner->status !== DocumentStatus::Confirmed || $payload->responseRecordedAt === null) {
            throw new \DomainException('RFQ must have a recorded response before conversion', 422);
        }
    }
}
