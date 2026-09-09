<?php

declare(strict_types=1);

namespace App\Shared\Contracts\BatchTraceability;

interface DocumentBatchTraceReader
{
    /**
     * @param  list<string>|null  $locationIds
     * @return list<ForwardDocumentBatchTraceData>
     */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
    ): array;

    /**
     * @param  list<string>|null  $locationIds
     * @return list<BackwardDocumentBatchTraceData>
     */
    public function backwardForPartner(
        string $tenantId,
        string $companyId,
        string $partnerId,
        ?array $locationIds,
        ?string $productId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array;

    /**
     * @param  list<string>  $locationIds
     * @return list<int>
     */
    public function batchIdsVisibleAtLocations(
        string $tenantId,
        string $companyId,
        array $locationIds,
    ): array;
}
