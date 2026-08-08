<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

/**
 * Input for StockAdjustmentDocumentService::createDraft().
 */
final class CreateStockAdjustmentData
{
    /**
     * @param  list<StockAdjustmentLineInput>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $createdByUserId,
        public readonly array $lines,
        public readonly ?string $note = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
