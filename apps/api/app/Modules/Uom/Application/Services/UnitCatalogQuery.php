<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Uom\Domain\Entities\UnitTextMapping;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use App\Shared\DTOs\UnitCatalogEntryData;

final readonly class UnitCatalogQuery implements UnitCatalogQueryInterface
{
    public function __construct(
        private UnitsProvisioningService $unitsProvisioning,
    ) {}

    /**
     * @return list<UnitCatalogEntryData>
     */
    public function visibleUnits(string $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);

        $entries = $this->unitsProvisioning
            ->visibleActiveUnits($company)
            ->map(static fn (object $unit): UnitCatalogEntryData => new UnitCatalogEntryData(
                id: (string) $unit->id,
                code: (string) $unit->code,
                name: (string) $unit->name,
                symbol: (string) $unit->symbol,
                decimalPlaces: (int) $unit->decimal_places,
                tier: $unit->company_id !== null
                    ? 'company'
                    : ($unit->tenant_id === null ? 'system' : 'tenant'),
                category: (string) ($unit->category ?? ''),
            ))
            ->all();

        usort($entries, static function (UnitCatalogEntryData $left, UnitCatalogEntryData $right): int {
            return UnitCatalogEntryData::tierRank($left->tier) <=> UnitCatalogEntryData::tierRank($right->tier)
                ?: strcmp($left->code, $right->code)
                ?: strcmp($left->id, $right->id);
        });

        return $entries;
    }

    public function visibleActiveUnitCount(string $companyId): int
    {
        return count($this->visibleUnits($companyId));
    }

    public function explicitMappingTarget(string $companyId, string $sourceText): ?UnitCatalogEntryData
    {
        $company = Company::query()->findOrFail($companyId);
        $targetId = UnitTextMapping::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('source_text', $sourceText)
            ->value('target_unit_id');
        if (! is_string($targetId)) {
            return null;
        }

        foreach ($this->visibleUnits($companyId) as $unit) {
            if ($unit->id === $targetId) {
                return $unit;
            }
        }

        return null;
    }

    public function explicitMappingTargetIds(string $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);
        $mappings = UnitTextMapping::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->pluck('target_unit_id', 'source_text');

        $targetIds = [];
        foreach ($mappings as $sourceText => $targetId) {
            if (is_string($sourceText) && is_string($targetId)) {
                $targetIds[$sourceText] = $targetId;
            }
        }

        return $targetIds;
    }
}
