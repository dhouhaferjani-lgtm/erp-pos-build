<?php

declare(strict_types=1);

namespace App\Shared\Contracts\BatchTraceability;

interface PosBatchTraceReader
{
    /**
     * @param  list<string>|null  $locationIds
     * @return list<ForwardPosBatchTraceData>
     */
    public function forwardForBatch(
        string $tenantId,
        string $companyId,
        int $batchId,
        ?array $locationIds,
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
