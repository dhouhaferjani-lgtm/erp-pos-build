<?php

declare(strict_types=1);

namespace App\Modules\Document\Infrastructure\BatchTraceability;

use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\BatchTraceability\BackwardDocumentBatchTraceData;
use App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader;
use App\Shared\Contracts\BatchTraceability\ForwardDocumentBatchTraceData;
use Illuminate\Database\Eloquent\Builder;

final readonly class DocumentBatchTraceReaderAdapter implements DocumentBatchTraceReader
{
    /** @param list<string>|null $locationIds */
    public function forwardForBatch(string $tenantId, string $companyId, int $batchId, ?array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return array_values($this->query($tenantId, $companyId, $locationIds)->where('batch_id', $batchId)
            ->with(['document.partner'])->get()->map(fn (DocumentLine $line) => new ForwardDocumentBatchTraceData(
                type: 'document', documentNumber: (string) $line->document->document_number,
                documentType: $line->document->type->value, documentDate: $line->document->document_date->toJSON(),
                partnerName: (string) (optional($line->document->partner)->name ?? 'Unknown'), partnerId: $line->document->partner_id,
                productName: $line->description, quantity: $line->quantity,
            ))->values()->all());
    }

    /** @param list<string>|null $locationIds */
    public function backwardForPartner(string $tenantId, string $companyId, string $partnerId, ?array $locationIds, ?string $productId, ?string $dateFrom, ?string $dateTo): array
    {
        if ($locationIds === []) {
            return [];
        }
        $query = $this->query($tenantId, $companyId, $locationIds)
            ->whereHas('document', function (Builder $query) use ($partnerId, $dateFrom, $dateTo): void {
                $query->whereRaw('partner_id = ?', [$partnerId]);
                if ($dateFrom !== null) {
                    $query->whereRaw('document_date >= ?', [$dateFrom]);
                }
                if ($dateTo !== null) {
                    $query->whereRaw('document_date <= ?', [$dateTo]);
                }
            });
        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return array_values($query->with(['document', 'batch'])->get()->map(fn (DocumentLine $line) => new BackwardDocumentBatchTraceData(
            batchNumber: $line->batch?->batch_number, batchId: (int) $line->batch_id,
            expiryDate: $line->batch?->expiry_date?->toDateString(), isRecalled: $line->batch !== null ? $line->batch->is_recalled : false,
            isExpired: $line->batch !== null ? $line->batch->is_expired : false, productName: $line->description,
            productId: (string) $line->product_id, quantity: $line->quantity, documentNumber: (string) $line->document->document_number,
            documentType: $line->document->type->value, documentDate: $line->document->document_date->toJSON(),
        ))->values()->all());
    }

    /** @param list<string> $locationIds */
    public function batchIdsVisibleAtLocations(string $tenantId, string $companyId, array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return array_values($this->query($tenantId, $companyId, $locationIds)->distinct()->pluck('batch_id')->map(fn ($id): int => (int) $id)->values()->all());
    }

    /**
     * @param  list<string>|null  $locationIds
     * @return Builder<DocumentLine>
     */
    private function query(string $tenantId, string $companyId, ?array $locationIds): Builder
    {
        $query = DocumentLine::query()->whereHas('document', fn (Builder $document) => $document
            ->whereRaw('tenant_id = ?', [$tenantId])->whereRaw('company_id = ?', [$companyId])
            ->whereIn('type', [DocumentType::Invoice, DocumentType::DeliveryNote]))->whereNotNull('batch_id');
        if ($locationIds !== null) {
            $query->where(function (Builder $line) use ($locationIds): void {
                $line->whereIn('document_lines.location_id', $locationIds)
                    ->orWhere(fn (Builder $fallback) => $fallback->whereNull('document_lines.location_id')
                        ->whereHas('document', fn (Builder $document) => $document->whereIn('location_id', $locationIds)));
            });
        }

        return $query;
    }
}
