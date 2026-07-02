<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

interface RepositoryInflowInterface
{
    /**
     * Increment a payment repository balance.
     *
     * @param  numeric-string  $amount
     */
    public function applyInflow(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $amount,
        string $currency,
    ): void;
}
