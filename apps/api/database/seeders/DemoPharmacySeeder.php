<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Str;

/**
 * DemoPharmacySeeder — Tunisia parapharmacy demo fixture.
 *
 * Extends {@see ParapharmacySeeder} to provision a Tunisia-localised
 * parapharmacy company (PharmaBio Tunisie SARL, TND/TN) under the
 * tenant slug `demo-pharmacy-tn`.
 *
 * Task 2 scope: tenant + company (TN/TND) + central warehouse (WH-01) +
 * Tunisia COA (via {@see TunisiaChartOfAccountsSeeder}) + Tunisia tax config
 * (VAT 19/13/7 + stamp duties via {@see TunisiaTaxConfigurationSeeder}).
 *
 * Task 3 will extend {@see createCompanyWithLocation()} further to add
 * the 4 Sousse/Tunis shop locations.
 */
final class DemoPharmacySeeder extends ParapharmacySeeder
{
    // ==================== Locale hooks ====================

    protected function localeCountryCode(): string
    {
        return 'TN';
    }

    protected function localeCurrency(): string
    {
        return 'TND';
    }

    /**
     * @return class-string<\Database\Seeders\Contracts\ChartOfAccountsSeederContract>
     */
    protected function localeChartOfAccountsSeeder(): string
    {
        return TunisiaChartOfAccountsSeeder::class;
    }

    protected function localeDefaultVatRate(): float
    {
        return 19.00;
    }

    protected function localeBarcodePrefix(): string
    {
        return '619';
    }

    protected function localePartnerFactoryState(): string
    {
        return 'tunisia';
    }

    protected function localeTenantSlug(): string
    {
        return 'demo-pharmacy-tn';
    }

    // ==================== Company creation override ====================

    /**
     * Create the Tunisia company with a single central warehouse location.
     *
     * Overrides the France-hardcoded values in the parent's
     * {@see ParapharmacySeeder::createCompanyWithLocation()} method to set
     * the full Tunisia identity (name, address in Sousse, matricule fiscal,
     * TN/TND currency) and a non-POS warehouse location.
     *
     * Task 3 will extend this override to add the 4 POS shop locations.
     *
     * @return array{0: Company, 1: Location}
     */
    protected function createCompanyWithLocation(Tenant $tenant): array
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PharmaBio Tunisie SARL',
            'legal_name' => 'PharmaBio Tunisie SARL',
            'country_code' => $this->localeCountryCode(),
            'tax_id' => '1234567AM000',
            'vat_number' => '1234567AM000',
            'currency' => $this->localeCurrency(),
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
            'address_street' => '12 Avenue Habib Bourguiba',
            'address_city' => 'Sousse',
            'address_postal_code' => '4000',
            'address_state' => 'Sousse',
            'phone' => '+216 73 000 000',
            'email' => 'contact@pharmabio.tn',
        ]);

        // Task 2: warehouse only — Task 3 adds the 4 shops.
        $warehouse = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'code' => 'WH-01',
            'name' => 'PharmaBio Entrepôt Central',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
            'tax_id' => null, // inherits company
            'address_street' => '12 Avenue Habib Bourguiba',
            'address_city' => 'Sousse',
            'address_postal_code' => '4000',
            'address_country' => 'TN',
            'phone' => '+216 73 000 000',
            'email' => 'warehouse@pharmabio.tn',
        ]);

        return [$company, $warehouse];
    }

    // ==================== run() ====================

    /**
     * Run the Tunisia demo seeds.
     *
     * Calls the parent {@see ParapharmacySeeder::run()} which:
     *   1. Creates the tenant (slug `demo-pharmacy-tn`) via {@see createParapharmacyTenant()}.
     *   2. Seeds reference data (roles, countries, ingredients, etc.).
     *   3. Creates the company + warehouse via our overridden
     *      {@see createCompanyWithLocation()} (TN identity).
     *   4. Calls {@see setupFinancialFoundation()} which invokes the Tunisia
     *      COA seeder via {@see localeChartOfAccountsSeeder()}.
     *   5. Provisions company tax via {@see CompanyTaxProvisioningService}
     *      (this seeds TN VAT bands from the tax_configurations table seeded
     *      by {@see TunisiaTaxConfigurationSeeder} below).
     *   6. Seeds products, partners, stock, and users.
     *
     * After the parent completes we additionally run
     * {@see TunisiaTaxConfigurationSeeder} inside the tenant context to
     * ensure VAT 19/13/7 + stamp duties are always present regardless of
     * whether the parent's provisioning service found matching rows.
     */
    public function run(): void
    {
        parent::run();

        // Ensure Tunisia tax configs (VAT 19/13/7 + stamp duties) are seeded.
        // TunisiaTaxConfigurationSeeder uses updateOrCreate so it is idempotent
        // and safe to run after the parent's CompanyTaxProvisioningService call.
        $tenant = Tenant::where('slug', $this->localeTenantSlug())->firstOrFail();
        $tenant->run(function (): void {
            $this->call(TunisiaTaxConfigurationSeeder::class);
        });
    }
}
