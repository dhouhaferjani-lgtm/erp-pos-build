<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\TaxConfigurationSummary;

interface TaxConfigurationLookupInterface
{
    /**
     * @param  array<int, string>  $ids
     * @return array<string, TaxConfigurationSummary>
     */
    public function findManyById(array $ids): array;
}
