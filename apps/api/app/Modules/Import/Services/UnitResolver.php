<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Data\UnitResolutionData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
use App\Modules\Import\Domain\Exceptions\CodedImportRowException;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use App\Shared\DTOs\UnitCatalogEntryData;

final readonly class UnitResolver
{
    /**
     * @param  list<UnitCatalogEntryData>|null  $runVisibleUnits
     * @param  array<string, string>|null  $runMappingTargetIds
     */
    public function __construct(
        private UnitCatalogQueryInterface $catalog,
        private ?string $runCompanyId = null,
        private ?array $runVisibleUnits = null,
        private ?array $runMappingTargetIds = null,
    ) {}

    public function forRun(string $companyId): self
    {
        return new self(
            $this->catalog,
            $companyId,
            $this->catalog->visibleUnits($companyId),
            $this->catalog->explicitMappingTargetIds($companyId),
        );
    }

    public function resolve(string $companyId, ?string $supplied, bool $isUpdate): UnitResolutionData
    {
        if ($this->runCompanyId !== null && $this->runCompanyId !== $companyId) {
            throw new \InvalidArgumentException('A unit-resolution run cannot be reused for another company.');
        }

        $code = trim($supplied ?? '');
        if ($code === '' && $isUpdate) {
            return new UnitResolutionData(null, null, null);
        }

        $defaulted = $code === '';
        $code = $defaulted ? 'pc' : $code;
        $visible = $this->runVisibleUnits ?? $this->catalog->visibleUnits($companyId);
        $matches = UnitCatalogEntryData::exactCodeMatchesAtWinningTier($visible, $code);

        if ($matches === []) {
            $explicitMapping = $defaulted
                ? null
                : $this->explicitMappingTarget($companyId, $code, $visible);
            if ($explicitMapping !== null) {
                return new UnitResolutionData(
                    $explicitMapping->id,
                    $explicitMapping->code,
                    null,
                );
            }

            $accepted = array_map(
                static fn (UnitCatalogEntryData $unit): string => $unit->code,
                $visible,
            );
            $errorCode = $defaulted
                ? ImportErrorCode::UnitDefaultMissing
                : ImportErrorCode::UnitUnknown;

            throw new CodedImportRowException(
                $errorCode,
                $defaulted
                    ? 'Default unit pc is not visible.'
                    : sprintf('Unknown unit "%s"; enter a code exactly as spelled.', $code),
                ['supplied' => $code, 'accepted' => $accepted],
            );
        }

        if (count($matches) > 1) {
            throw new CodedImportRowException(
                ImportErrorCode::UnitAmbiguous,
                sprintf('Unit code "%s" matches more than one visible unit.', $code),
                [
                    'supplied' => $code,
                    'candidates' => array_map(
                        static fn (UnitCatalogEntryData $unit): array => [
                            'id' => $unit->id,
                            'code' => $unit->code,
                            'name' => $unit->name,
                            'category' => $unit->category,
                            'tier' => $unit->tier,
                        ],
                        $matches,
                    ),
                ],
            );
        }

        $match = $matches[0];

        return new UnitResolutionData(
            $match->id,
            $match->code,
            $defaulted ? ImportWarningCode::UnitDefaulted : null,
        );
    }

    /** @param list<UnitCatalogEntryData> $visible */
    private function explicitMappingTarget(
        string $companyId,
        string $sourceText,
        array $visible,
    ): ?UnitCatalogEntryData {
        if ($this->runMappingTargetIds === null) {
            return $this->catalog->explicitMappingTarget($companyId, $sourceText);
        }

        $targetId = $this->runMappingTargetIds[$sourceText] ?? null;
        if ($targetId === null) {
            return null;
        }

        foreach ($visible as $unit) {
            if ($unit->id === $targetId) {
                return $unit;
            }
        }

        return null;
    }
}
