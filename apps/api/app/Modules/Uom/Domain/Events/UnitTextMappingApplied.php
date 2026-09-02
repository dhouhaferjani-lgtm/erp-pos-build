<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class UnitTextMappingApplied extends DomainEvent
{
    public function __construct(
        public readonly string $mappingId,
        public readonly string $companyId,
        public readonly ?string $sourceText,
        public readonly string $targetUnitId,
        public readonly string $targetUnitCode,
        public readonly int $productCount,
        public readonly int $importRowCount,
    ) {
        parent::__construct($mappingId);
    }

    public function getEventName(): string
    {
        return 'uom.unit_text_mapping_applied';
    }

    /** @return array<string, mixed> */
    public function getAuditPayload(): array
    {
        return [
            'source_text' => $this->sourceText,
            'target_unit_id' => $this->targetUnitId,
            'target_unit_code' => $this->targetUnitCode,
            'product_count' => $this->productCount,
            'import_row_count' => $this->importRowCount,
        ];
    }
}
