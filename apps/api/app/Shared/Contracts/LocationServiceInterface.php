<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for location operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface LocationServiceInterface
{
    /**
     * Find a location ID by code.
     *
     * @return string|null Location ID or null if not found
     */
    public function findIdByCode(string $companyId, string $code): ?string;
}
