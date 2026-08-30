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
    public function __construct(
        private UnitCatalogQueryInterface $catalog,
    ) {}

    public function resolve(string $companyId, ?string $supplied, bool $isUpdate): UnitResolutionData
    {
        $code = trim($supplied ?? '');
        if ($code === '' && $isUpdate) {
            return new UnitResolutionData(null, null, null);
        }

        $defaulted = $code === '';
        $code = $defaulted ? 'pc' : $code;
        $visible = $this->catalog->visibleUnits($companyId);
        $matches = UnitCatalogEntryData::exactCodeMatchesAtWinningTier($visible, $code);

        if ($matches === []) {
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
}
