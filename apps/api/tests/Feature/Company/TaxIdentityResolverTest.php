<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Models\Country;
use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_value_overrides_company_per_field(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
            'tax_id' => 'BRANCH-TAX',
            'legal_identifiers' => ['siret' => 'BRANCH-SIRET'],
        ]);

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertSame('BRANCH-TAX', $resolved->taxId);
        $this->assertSame('COMPANY-VAT', $resolved->vatNumber);
        $this->assertSame('BRANCH-SIRET', $resolved->legalIdentifiers['siret']);
        $this->assertSame('FR', $resolved->countryCode);
    }

    public function test_partial_legal_identifier_override_inherits_missing_company_keys(): void
    {
        $company = $this->makeCompany();
        $company->update([
            'legal_identifiers' => [
                'siret' => 'COMPANY-SIRET',
                'registry' => 'COMPANY-REGISTRY',
            ],
        ]);
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'B',
            'code' => 'B',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
            'legal_identifiers' => ['siret' => 'BRANCH-SIRET'],
        ]);

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertSame('BRANCH-SIRET', $resolved->legalIdentifiers['siret']);
        $this->assertSame('COMPANY-REGISTRY', $resolved->legalIdentifiers['registry']);
    }

    public function test_all_null_inherits_company(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'B2',
            'code' => 'B2',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertSame('COMPANY-TAX', $resolved->taxId);
        $this->assertSame('COMPANY-VAT', $resolved->vatNumber);
        $this->assertSame('COMPANY-SIRET', $resolved->legalIdentifiers['siret']);
    }

    public function test_location_country_overrides_company_country(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'B3',
            'code' => 'B3',
            'type' => LocationType::Shop,
            'address_country' => 'TN',
            'is_default' => false,
            'is_active' => true,
        ]);

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertSame('TN', $resolved->countryCode);
    }

    public function test_resolver_uses_soft_deleted_company_for_fallback(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'B4',
            'code' => 'B4',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
        ]);
        $company->delete();

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertSame('COMPANY-TAX', $resolved->taxId);
        $this->assertSame('COMPANY-VAT', $resolved->vatNumber);
        $this->assertSame('FR', $resolved->countryCode);
    }

    public function test_resolver_exposes_per_country_tax_id_label(): void
    {
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'tax_id_label' => 'Matricule Fiscal',
        ]);

        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Tunis Branch',
            'code' => 'TN1',
            'type' => LocationType::Shop,
            'address_country' => 'TN',
            'tax_id' => 'TN-BRANCH-MF',
            'is_default' => false,
            'is_active' => true,
        ]);

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertSame('TN-BRANCH-MF', $resolved->taxId);
        $this->assertSame('Matricule Fiscal', $resolved->taxIdLabel);
    }

    public function test_resolver_tax_id_label_is_null_when_country_unknown(): void
    {
        $company = $this->makeCompany();
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Branch',
            'code' => 'BX',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
        ]);

        $resolved = (new TaxIdentityResolver)->resolve($location);

        $this->assertNull($resolved->taxIdLabel);
    }

    private function makeCompany(): Company
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'legal_name' => 'C LLC',
            'tax_id' => 'COMPANY-TAX',
            'vat_number' => 'COMPANY-VAT',
            'legal_identifiers' => ['siret' => 'COMPANY-SIRET'],
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }
}
