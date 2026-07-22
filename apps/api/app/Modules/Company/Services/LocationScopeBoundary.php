<?php

declare(strict_types=1);

namespace App\Modules\Company\Services;

use App\Modules\Company\Domain\Location;

/** Shared active-location boundary used by all financial read surfaces. */
final class LocationScopeBoundary
{
    /** @return list<string> */
    public function activeLocationIds(string $companyId): array
    {
        return array_values(Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /** @param list<string> $effectiveLocationIds */
    public function isUnrestricted(string $companyId, array $effectiveLocationIds): bool
    {
        return count(array_diff($this->activeLocationIds($companyId), $effectiveLocationIds)) === 0;
    }
}
