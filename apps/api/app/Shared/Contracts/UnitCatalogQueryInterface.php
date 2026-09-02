<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\UnitCatalogEntryData;

interface UnitCatalogQueryInterface
{
    /**
     * @return list<UnitCatalogEntryData>
     */
    public function visibleUnits(string $companyId): array;

    public function visibleActiveUnitCount(string $companyId): int;

    public function explicitMappingTarget(string $companyId, string $sourceText): ?UnitCatalogEntryData;

    /** @return array<string, string> Source text keyed to target unit ID. */
    public function explicitMappingTargetIds(string $companyId): array;
}
