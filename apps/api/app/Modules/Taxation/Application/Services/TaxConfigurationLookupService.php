<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Shared\Contracts\TaxConfigurationLookupInterface;
use App\Shared\DTOs\TaxConfigurationSummary;

final class TaxConfigurationLookupService implements TaxConfigurationLookupInterface
{
    /**
     * @param  array<int, string>  $ids
     * @return array<string, TaxConfigurationSummary>
     */
    public function findManyById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TaxConfiguration::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->mapWithKeys(
                fn (TaxConfiguration $configuration): array => [
                    $configuration->id => new TaxConfigurationSummary(
                        id: $configuration->id,
                        percentageRate: $configuration->percentage_rate !== null ? (string) $configuration->percentage_rate : null,
                    ),
                ],
            )
            ->all();
    }
}
