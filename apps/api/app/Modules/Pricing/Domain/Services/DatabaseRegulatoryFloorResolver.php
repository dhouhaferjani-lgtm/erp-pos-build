<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Services;

use App\Modules\Pricing\Domain\CountryPricingRegulation;
use App\Modules\Pricing\Domain\Enums\RegulatoryRuleType;
use App\Shared\Contracts\RegulatoryFloorResolverInterface;

/**
 * Reads active below-cost regulations from `country_pricing_regulations`.
 * Results are memoised per country for the resolver's lifetime so a batched
 * document resolution issues at most one query per distinct country.
 */
final class DatabaseRegulatoryFloorResolver implements RegulatoryFloorResolverInterface
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function belowCostFloorActive(string $countryCode): bool
    {
        return $this->cache[$countryCode] ??= CountryPricingRegulation::query()
            ->where('country_code', $countryCode)
            ->where('rule_type', RegulatoryRuleType::BelowCostFloor->value)
            ->where('active', true)
            ->exists();
    }
}
