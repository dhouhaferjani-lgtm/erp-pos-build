<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\VerificationStatus;
use App\Modules\Company\Domain\Enums\VerificationTier;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0.1.2: Tests for companies table.
 *
 * Company = Legal entity with tax_id, country_code, compliance profile.
 * A tenant (account holder) can have multiple companies.
 */
class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    public function test_company_can_be_created_with_required_fields(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ACME Garage SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->assertDatabaseHas('companies', [
            'tenant_id' => $this->tenant->id,
            'name' => 'ACME Garage SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $this->assertNotNull($company->id);
        $this->assertEquals(36, strlen($company->id)); // UUID length
    }

    public function test_company_belongs_to_tenant(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Garage Paris',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $this->assertEquals($this->tenant->id, $company->tenant->id);
    }

    public function test_tenant_can_have_multiple_companies(): void
    {
        $company1 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Garage Tunisia',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Garage France',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $this->assertCount(2, $this->tenant->fresh()->companies);
    }

    public function test_company_has_legal_info_fields(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ACME SARL',
            'legal_name' => 'ACME Services Automobiles SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'tax_id' => '1234567ABC',
            'registration_number' => 'RC-2024-001',
            'vat_number' => 'TN1234567',
        ]);

        $this->assertEquals('ACME Services Automobiles SARL', $company->legal_name);
        $this->assertEquals('1234567ABC', $company->tax_id);
        $this->assertEquals('RC-2024-001', $company->registration_number);
        $this->assertEquals('TN1234567', $company->vat_number);
    }

    public function test_company_has_address_fields(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'address_street' => '123 Rue de la Paix',
            'address_street_2' => 'Bâtiment A',
            'address_city' => 'Paris',
            'address_state' => 'Île-de-France',
            'address_postal_code' => '75001',
        ]);

        $this->assertEquals('123 Rue de la Paix', $company->address_street);
        $this->assertEquals('Paris', $company->address_city);
        $this->assertEquals('75001', $company->address_postal_code);
    }

    public function test_company_has_document_sequence_fields(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Check defaults
        $company->refresh();
        $this->assertEquals('FAC-', $company->invoice_prefix);
        $this->assertEquals(1, $company->invoice_next_number);
        $this->assertEquals('DEV-', $company->quote_prefix);
        $this->assertEquals(1, $company->quote_next_number);
    }

    public function test_company_has_verification_fields(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Check defaults
        $company->refresh();
        $this->assertEquals(VerificationTier::Basic, $company->verification_tier);
        $this->assertEquals(VerificationStatus::Pending, $company->verification_status);
    }

    public function test_company_status_enum(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->assertEquals(CompanyStatus::Active, $company->status);
        $this->assertTrue($company->isActive());
    }

    public function test_company_tax_id_unique_within_tenant(): void
    {
        Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'First Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'tax_id' => 'SAME-TAX-ID',
        ]);

        $this->expectException(QueryException::class);

        Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'tax_id' => 'SAME-TAX-ID', // Duplicate within same tenant
        ]);
    }

    public function test_company_tax_id_can_be_same_across_different_tenants(): void
    {
        $tenant2 = Tenant::create([
            'name' => 'Another Account',
            'slug' => 'another-account',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company1 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant1 Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'tax_id' => 'SAME-TAX-ID',
        ]);

        $company2 = Company::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Tenant2 Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'tax_id' => 'SAME-TAX-ID', // OK - different tenant
        ]);

        $this->assertNotEquals($company1->id, $company2->id);
    }

    public function test_company_supports_soft_deletes(): void
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'To Delete',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $company->delete();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
        $this->assertNotNull(Company::withTrashed()->find($company->id));
    }

    public function test_company_can_have_parent_company(): void
    {
        $headquarter = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Headquarter',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'is_headquarters' => true,
        ]);

        $branch = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch Tunisia',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'parent_company_id' => $headquarter->id,
        ]);

        $this->assertEquals($headquarter->id, $branch->parent_company_id);
        $this->assertEquals($headquarter->id, $branch->parentCompany->id);
        $this->assertTrue($headquarter->is_headquarters);
    }
}
