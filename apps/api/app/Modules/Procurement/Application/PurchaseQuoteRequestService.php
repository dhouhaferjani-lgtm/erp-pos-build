<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Procurement\Domain\Dto\RfqPayload;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PurchaseQuoteRequestService
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly DatabaseManager $db,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @return Collection<int, Document>
     */
    public function createGroup(CreateRfqData $data, string $tenantId, string $companyId, string $currency): Collection
    {
        return $this->db->transaction(function () use ($data, $tenantId, $companyId, $currency): Collection {
            $groupId = (string) Str::uuid7();
            $documents = collect();
            $scale = $this->scaleResolver->getScale($currency);

            foreach ($data->partnerIds as $partnerId) {
                $document = Document::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'partner_id' => $partnerId,
                    'type' => DocumentType::PurchaseQuoteRequest,
                    'fiscal_category' => FiscalCategory::NonFiscal,
                    'fiscal_status' => FiscalStatus::Draft,
                    'status' => DocumentStatus::Draft,
                    'document_number' => $this->numberingService->generateNumber(
                        $tenantId,
                        $companyId,
                        DocumentType::PurchaseQuoteRequest,
                    ),
                    'document_date' => now()->toDateString(),
                    'currency' => $currency,
                    'subtotal' => CurrencyScale::bcformatStrict('0', $scale),
                    'discount_amount' => CurrencyScale::bcformatStrict('0', $scale),
                    'tax_amount' => CurrencyScale::bcformatStrict('0', $scale),
                    'total' => CurrencyScale::bcformatStrict('0', $scale),
                    'balance_due' => CurrencyScale::bcformatStrict('0', $scale),
                    'notes' => $data->notes,
                    'payload' => (new RfqPayload(
                        groupId: $groupId,
                        validityDate: $data->validityDate,
                        supplierReference: null,
                        leadTimeDays: null,
                        responseRecordedAt: null,
                        sentAt: null,
                        closedReason: null,
                    ))->toArray(),
                ]);

                $this->replaceLines($document, $data->lines, $scale);
                $document->load('lines');
                $documents->push($document);
            }

            return $documents;
        });
    }

    public function recordResponse(string $rfqId, string $tenantId, string $companyId, UpdateRfqData $data): Document
    {
        return $this->db->transaction(function () use ($rfqId, $tenantId, $companyId, $data): Document {
            $document = $this->rfqQuery($tenantId, $companyId)->findOrFail($rfqId);
            $scale = $this->scaleResolver->getScale($document->currency);
            $payload = RfqPayload::fromArray($document->payload ?? []);

            if ($document->status === DocumentStatus::Cancelled) {
                throw new \DomainException('RFQ_CANCELLED', 422);
            }

            $document->status = DocumentStatus::Confirmed;
            $document->payload = (new RfqPayload(
                groupId: $payload->groupId,
                validityDate: $data->validityDate,
                supplierReference: $data->supplierReference,
                leadTimeDays: $data->leadTimeDays,
                responseRecordedAt: now()->toIso8601String(),
                sentAt: $payload->sentAt,
                closedReason: $payload->closedReason,
            ))->toArray();
            $document->save();

            $this->replaceLines($document, $data->lines, $scale);

            return $document->refresh()->load('lines');
        });
    }

    public function markSent(string $rfqId, string $tenantId, string $companyId): Document
    {
        return $this->db->transaction(function () use ($rfqId, $tenantId, $companyId): Document {
            $document = $this->rfqQuery($tenantId, $companyId)->findOrFail($rfqId);
            $payload = RfqPayload::fromArray($document->payload ?? []);

            if ($document->status === DocumentStatus::Cancelled) {
                throw new \DomainException('RFQ_CANCELLED', 422);
            }

            $document->status = DocumentStatus::Confirmed;
            $document->payload = (new RfqPayload(
                groupId: $payload->groupId,
                validityDate: $payload->validityDate,
                supplierReference: $payload->supplierReference,
                leadTimeDays: $payload->leadTimeDays,
                responseRecordedAt: $payload->responseRecordedAt,
                sentAt: now()->toIso8601String(),
                closedReason: $payload->closedReason,
            ))->toArray();
            $document->save();

            return $document->refresh()->load('lines');
        });
    }

    public function reopenGroup(string $groupId, string $tenantId, string $companyId): int
    {
        return $this->db->transaction(function () use ($groupId, $tenantId, $companyId): int {
            $documents = $this->rfqQuery($tenantId, $companyId)
                ->whereRaw("payload->'rfq'->>'group_id' = ?", [$groupId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($documents->isEmpty()) {
                throw (new ModelNotFoundException)->setModel(Document::class);
            }

            $siblingIds = $documents->pluck('id')->all();

            $hasLivePo = Document::query()
                ->where('type', DocumentType::PurchaseOrder)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereIn('source_document_id', $siblingIds)
                ->where('status', '!=', DocumentStatus::Cancelled)
                ->exists();

            if ($hasLivePo) {
                throw new \DomainException('RFQ_GROUP_ALREADY_AWARDED', 422);
            }

            $count = 0;

            foreach ($documents as $document) {
                if ($document->status !== DocumentStatus::Cancelled) {
                    continue;
                }

                $payload = RfqPayload::fromArray($document->payload ?? []);
                if ($payload->closedReason !== 'lost') {
                    continue;
                }

                $document->status = $payload->sentAt !== null || $payload->responseRecordedAt !== null
                    ? DocumentStatus::Confirmed
                    : DocumentStatus::Draft;
                $document->payload = (new RfqPayload(
                    groupId: $payload->groupId,
                    validityDate: $payload->validityDate,
                    supplierReference: $payload->supplierReference,
                    leadTimeDays: $payload->leadTimeDays,
                    responseRecordedAt: $payload->responseRecordedAt,
                    sentAt: $payload->sentAt,
                    closedReason: null,
                ))->toArray();
                $document->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * @param  list<array{product_id: string, variant_id?: string|null, quantity: string, unit_price?: string|null, description?: string|null}>|list<array{id?: string|null, product_id: string, variant_id?: string|null, quantity: string, unit_price?: string|null, description?: string|null}>  $lines
     */
    private function replaceLines(Document $document, array $lines, int $scale): void
    {
        $document->lines()->delete();

        $subtotal = CurrencyScale::bcformatStrict('0', $scale);
        foreach ($lines as $index => $line) {
            $unitPrice = $line['unit_price'] ?? null;
            $price = CurrencyScale::bcformatStrict($this->numericString($unitPrice !== null ? $unitPrice : '0'), $scale);
            $quantity = $this->numericString($line['quantity']);
            $lineTotal = bcmul($quantity, $price, $scale);
            $subtotal = bcadd($subtotal, $lineTotal, $scale);

            DocumentLine::create([
                'document_id' => $document->id,
                'line_number' => $index + 1,
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'description' => $line['description'] ?? 'RFQ line',
                'quantity' => $quantity,
                'unit_price' => $price,
                'line_total' => $lineTotal,
                'allocated_costs' => '0.000000',
            ]);
        }

        $document->subtotal = $subtotal;
        $document->total = $subtotal;
        $document->balance_due = CurrencyScale::bcformatStrict('0', $scale);
        $document->save();
    }

    /**
     * @return Builder<Document>
     */
    private function rfqQuery(string $tenantId, string $companyId): Builder
    {
        return Document::query()
            ->where('type', DocumentType::PurchaseQuoteRequest)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);
    }

    /**
     * @return numeric-string
     */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Expected numeric string.');
        }

        return $value;
    }
}
