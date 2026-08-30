<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\Services;

use App\Modules\Company\Domain\Company;
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
}
