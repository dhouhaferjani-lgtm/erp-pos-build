<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

interface RepositoryOutflowInterface
{
    /**
     * Decrement a payment repository's balance (cash/bank out).
     *
     * Loads the repository scoped to tenant + company, decrements the balance
     * by $amount using bcmath at the currency's decimal scale, persists the
     * change inside a transaction with a row-level lock, and fires
     * RepositoryBalanceChanged after commit.
     *
     * @param  numeric-string  $amount
     */
    public function applyOutflow(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $amount,
        string $currency,
    ): void;
}
