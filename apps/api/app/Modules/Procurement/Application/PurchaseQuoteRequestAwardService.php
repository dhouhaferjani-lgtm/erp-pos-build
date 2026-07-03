<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Procurement\Domain\Dto\RfqPayload;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class PurchaseQuoteRequestAwardService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentConverterRegistry $converterRegistry,
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

            $purchaseOrder = $this->converterRegistry->convert($lockedWinner->loadMissing('lines'), DocumentType::PurchaseOrder);

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
