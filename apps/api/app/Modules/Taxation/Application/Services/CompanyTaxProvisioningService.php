<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Taxation\Application\Registries\CountryTaxConfigurationRegistry;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single entry point for giving a Company its country tax configurations and a
 * sensible company-level default tax. Idempotent. Call from EVERY company writer.
 *
 * Runs on the active (per-tenant) connection — the caller must have the tenant
 * connection initialized and `countries` seeded before calling.
 */
final class CompanyTaxProvisioningService
{
    public function __construct(
        private readonly CountryTaxConfigurationRegistry $registry = new CountryTaxConfigurationRegistry,
        private readonly bool $failLoudOnMissingCountry = false,
    ) {}

    public function provisionForCompany(Company $company): void
    {
        $countryCode = strtoupper($company->country_code);

        $seederClass = $this->registry->seederFor($countryCode);
        if ($seederClass === null) {
            return;
        }

        $countryExists = DB::table('countries')->where('code', $countryCode)->exists();
        if (! $countryExists) {
            if ($this->failLoudOnMissingCountry) {
                throw new RuntimeException(
                    "Cannot seed tax configurations: country '{$countryCode}' is missing from the countries table. "
                    .'Seed reference data (CountriesSeeder) before provisioning company tax.'
                );
            }

            return;
        }

        $this->runSeeder($seederClass);

        $default = TaxConfiguration::where('country_code', $countryCode)
            ->where('is_default', true)
            ->first();

        if ($default !== null) {
            // percentage_rate is cast decimal:4 ('19.0000'); companies.default_tax_rate is decimal(5,2).
            // Format to 2dp for consistent cross-driver storage (SQLite vs PG).
            $rawRate = (string) ($default->percentage_rate ?? '0');
            /** @var numeric-string $numericRate */
            $numericRate = is_numeric($rawRate) ? $rawRate : '0';
            $rate = bcadd($numericRate, '0', 2); // precision-ok: tax percentage column is always decimal(5,2)
            $company->update([
                'default_tax_configuration_id' => $default->id,
                'default_tax_rate' => $rate,
            ]);
        }
    }

    /**
     * Instantiate and run the country-specific tax configuration seeder.
     *
     * @param  class-string<TunisiaTaxConfigurationSeeder>|class-string<FranceTaxConfigurationSeeder>  $seederClass
     */
    private function runSeeder(string $seederClass): void
    {
        /** @var TunisiaTaxConfigurationSeeder|FranceTaxConfigurationSeeder $seeder */
        $seeder = new $seederClass;
        $seeder->run();
    }
}
