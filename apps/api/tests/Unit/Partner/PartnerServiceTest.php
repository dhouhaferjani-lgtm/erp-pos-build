<?php

declare(strict_types=1);

namespace Tests\Unit\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Partner\Application\Services\PartnerService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private PartnerService $service;

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

        $this->service = new PartnerService;
    }

    public function test_upsert_with_type_merge_creates_new_partner(): void
    {
        $partnerId = $this->service->upsertWithTypeMerge(
            $this->tenant->id,
            $this->company->id,
            [
                'name' => 'New Partner',
                'type' => 'customer',
                'email' => 'new@partner.com',
                'phone' => '+33123456789',
                'vat_number' => 'FR12345678901',
            ]
        );

        $this->assertIsString($partnerId);

        $partner = Partner::find($partnerId);
        $this->assertNotNull($partner);
        $this->assertEquals('New Partner', $partner->name);
        $this->assertEquals(PartnerType::Customer, $partner->type);
        $this->assertEquals('new@partner.com', $partner->email);
        $this->assertEquals('FR12345678901', $partner->vat_number);
    }

    public function test_upsert_with_type_merge_updates_existing_partner_same_type(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Existing Partner',
            'type' => PartnerType::Customer,
            'vat_number' => 'FR99999999999',
            'email' => 'old@partner.com',
        ]);

        $partnerId = $this->service->upsertWithTypeMerge(
            $this->tenant->id,
            $this->company->id,
            [
                'name' => 'Updated Partner',
                'type' => 'customer',
                'email' => 'updated@partner.com',
                'vat_number' => 'FR99999999999',
            ]
        );

        $partner = Partner::find($partnerId);
        $this->assertNotNull($partner);
        $this->assertEquals('Updated Partner', $partner->name);
        $this->assertEquals(PartnerType::Customer, $partner->type);
        $this->assertEquals('updated@partner.com', $partner->email);

        // Should not have created a second partner
        $count = Partner::where('vat_number', 'FR99999999999')->count();
        $this->assertEquals(1, $count);
    }

    public function test_upsert_with_type_merge_merges_customer_and_supplier_into_both(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer Only',
            'type' => PartnerType::Customer,
            'vat_number' => 'FR11111111111',
        ]);

        $partnerId = $this->service->upsertWithTypeMerge(
            $this->tenant->id,
            $this->company->id,
            [
                'name' => 'Customer Only',
                'type' => 'supplier',
                'vat_number' => 'FR11111111111',
            ]
        );

        $partner = Partner::find($partnerId);
        $this->assertNotNull($partner);
        $this->assertEquals(PartnerType::Both, $partner->type);

        // Should still be one record
        $count = Partner::where('vat_number', 'FR11111111111')->count();
        $this->assertEquals(1, $count);
    }

    public function test_find_by_vat_or_name_finds_by_vat_number(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'VAT Partner',
            'type' => PartnerType::Customer,
            'vat_number' => 'FR55555555555',
        ]);

        $result = $this->service->findByVatOrName(
            $this->tenant->id,
            $this->company->id,
            'FR55555555555',
            'Some Other Name'
        );

        $this->assertNotNull($result);
        $this->assertEquals($partner->id, $result['id']);
        $this->assertEquals('customer', $result['type']);
    }

    public function test_find_by_vat_or_name_finds_by_name_when_vat_is_null(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Named Partner',
            'type' => PartnerType::Supplier,
        ]);

        $result = $this->service->findByVatOrName(
            $this->tenant->id,
            $this->company->id,
            null,
            'Named Partner'
        );

        $this->assertNotNull($result);
        $this->assertEquals($partner->id, $result['id']);
        $this->assertEquals('supplier', $result['type']);
    }

    public function test_find_by_vat_or_name_returns_null_when_not_found(): void
    {
        $result = $this->service->findByVatOrName(
            $this->tenant->id,
            $this->company->id,
            'FR00000000000',
            'Nonexistent Partner'
        );

        $this->assertNull($result);
    }
}
