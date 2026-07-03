<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Enums\ModuleName;
use App\Modules\Company\Domain\Company;
use App\Services\CompanyConfigService;

final class PurchaseBonusGate
{
    public function __construct(
        private readonly CompanyConfigService $companyConfigService,
    ) {}

    public function enabledFor(Company $company): bool
    {
        if (! $company->relationLoaded('tenant')) {
            $company->load('tenant');
        }

        $config = $this->companyConfigService->getConfigForTenant($company->tenant);

        if (! $config->hasModule(ModuleName::PurchaseBonus->value)) {
            return false;
        }

        /** @var array<int, string> $countries */
        $countries = config('procurement.bonus_quantity_countries', ['TN']);
        $countries = array_map(
            static fn (string $country): string => strtoupper($country),
            $countries,
        );

        return $countries === [] || in_array(strtoupper((string) $company->country_code), $countries, true);
    }
}
