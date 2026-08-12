<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use Database\Seeders\Contracts\ChartOfAccountsSeederContract;
use DomainException;
use Illuminate\Database\Seeder;

/**
 * Activation-aware chart seeder for country-parameterized demo fixtures.
 */
final class CountryDefaultsChartOfAccountsSeeder extends Seeder implements ChartOfAccountsSeederContract
{
    public function __construct(
        private readonly ChartOfAccountsService $charts,
        private readonly string $countryCode,
    ) {}

    public function run(string $companyId, ?string $tenantId = null): void
    {
        $company = Company::query()
            ->when($tenantId !== null, static fn ($query) => $query->where('tenant_id', $tenantId))
            ->findOrFail($companyId);

        if (strtoupper(trim($company->country_code)) !== strtoupper(trim($this->countryCode))) {
            throw new DomainException('The chart seeder country does not match the target company.');
        }

        $this->charts->seedForCompany($company);
    }
}
