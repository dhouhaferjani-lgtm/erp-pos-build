<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for composite item operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface CompositeItemServiceInterface
{
    /**
     * Create or update a composite item by code.
     *
     * @param  array<string, mixed>  $data  Composite item data
     * @return string The composite item ID
     */
    public function upsert(
        string $tenantId,
        string $companyId,
        array $data
    ): string;
}
