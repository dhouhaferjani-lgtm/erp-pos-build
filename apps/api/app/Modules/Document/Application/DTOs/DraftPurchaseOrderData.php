<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

final class DraftPurchaseOrderData
{
    /** @param list<DraftPurchaseOrderLineData> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $supplierId,
        public readonly string $destinationLocationId,
        public readonly string $createdByUserId,
        public readonly array $lines,
    ) {}
}
