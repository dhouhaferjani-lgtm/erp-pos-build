<?php

declare(strict_types=1);

namespace App\Modules\POS\Infrastructure\BatchTraceability;

use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Shared\Contracts\BatchTraceability\ForwardPosBatchTraceData;
use App\Shared\Contracts\BatchTraceability\PosBatchTraceReader;
use Illuminate\Database\Eloquent\Builder;

final readonly class PosBatchTraceReaderAdapter implements PosBatchTraceReader
{
    /** @param list<string>|null $locationIds */
    public function forwardForBatch(string $tenantId, string $companyId, int $batchId, ?array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return array_values($this->query($tenantId, $companyId, $locationIds)->where('batch_id', $batchId)->with('receipt')->get()
            ->map(fn (ReceiptLineBatchAllocation $allocation) => new ForwardPosBatchTraceData(
                type: 'pos_receipt', receiptNumber: $allocation->receipt?->receipt_number,
                saleDate: $allocation->receipt?->created_at?->toDateString(), customerName: $allocation->receipt?->customer_name,
                customerIdentifier: $allocation->receipt?->customer_identifier, batchNumber: $allocation->batch_number,
                quantity: $allocation->quantity,
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
     * @return Builder<ReceiptLineBatchAllocation>
     */
    private function query(string $tenantId, string $companyId, ?array $locationIds): Builder
    {
        return ReceiptLineBatchAllocation::query()->whereHas('receipt', function (Builder $receipt) use ($tenantId, $companyId, $locationIds): void {
            $receipt->whereRaw('tenant_id = ?', [$tenantId])->whereRaw('company_id = ?', [$companyId]);
            if ($locationIds !== null) {
                $receipt->whereIn('location_id', $locationIds);
            }
        });
    }
}
