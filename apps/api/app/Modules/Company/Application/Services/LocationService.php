<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Shared\Contracts\LocationServiceInterface;

/**
 * Application service for location operations.
 *
 * Exposes location functionality to other modules through the LocationServiceInterface.
 */
final class LocationService implements LocationServiceInterface
{
    /**
     * Find a location ID by code.
     *
     * @return string|null Location ID or null if not found
     */
    public function findIdByCode(string $companyId, string $code): ?string
    {
        $location = Location::where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        return $location?->id;
    }
}
