<?php

declare(strict_types=1);

namespace Tests\Unit\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerEntityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    public function test_partner_has_uuid_primary_key(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->assertIsString($partner->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $partner->id
        );
    }

    public function test_partner_belongs_to_tenant(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->assertEquals($this->tenant->id, $partner->tenant_id);
        $this->assertEquals($this->tenant->id, $partner->tenant->id);
    }

    public function test_partner_type_is_enum(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->assertInstanceOf(PartnerType::class, $partner->type);
        $this->assertEquals(PartnerType::Customer, $partner->type);
    }

    public function test_partner_can_be_customer(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->assertTrue($partner->isCustomer());
        $this->assertFalse($partner->isSupplier());
    }

    public function test_partner_can_be_supplier(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier Partner',
            'type' => PartnerType::Supplier,
        ]);

        $this->assertFalse($partner->isCustomer());
        $this->assertTrue($partner->isSupplier());
    }

    public function test_partner_can_be_both(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Both Partner',
            'type' => PartnerType::Both,
        ]);

        $this->assertTrue($partner->isCustomer());
        $this->assertTrue($partner->isSupplier());
    }

    public function test_partner_has_fillable_fields(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Full Partner',
            'type' => PartnerType::Customer,
            'email' => 'partner@example.com',
            'phone' => '+33123456789',
            'country_code' => 'FR',
            'vat_number' => 'FR12345678901',
            'notes' => 'Some notes about this partner',
        ]);

        $this->assertEquals('Full Partner', $partner->name);
        $this->assertEquals('partner@example.com', $partner->email);
        $this->assertEquals('+33123456789', $partner->phone);
        $this->assertEquals('FR', $partner->country_code);
        $this->assertEquals('FR12345678901', $partner->vat_number);
        $this->assertEquals('Some notes about this partner', $partner->notes);
    }

    public function test_partner_uses_soft_deletes(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Delete Me',
            'type' => PartnerType::Customer,
        ]);

        $partnerId = $partner->id;
        $partner->delete();

        // Should not be found by default
        $this->assertNull(Partner::find($partnerId));

        // Should be found with trashed
        $this->assertNotNull(Partner::withTrashed()->find($partnerId));
    }

    public function test_partner_has_display_name(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
        ]);

        $this->assertEquals('ACME Corporation', $partner->getDisplayName());
    }

    public function test_partner_has_code(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
            'code' => 'CUST-001',
        ]);

        $this->assertEquals('CUST-001', $partner->code);
    }

    public function test_partner_scope_customers(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer',
            'type' => PartnerType::Customer,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier',
            'type' => PartnerType::Supplier,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Both',
            'type' => PartnerType::Both,
        ]);

        $customers = Partner::customers()->get();

        $this->assertCount(2, $customers);
    }

    public function test_partner_scope_suppliers(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer',
            'type' => PartnerType::Customer,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier',
            'type' => PartnerType::Supplier,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Both',
            'type' => PartnerType::Both,
        ]);

        $suppliers = Partner::suppliers()->get();

        $this->assertCount(2, $suppliers);
    }
}
