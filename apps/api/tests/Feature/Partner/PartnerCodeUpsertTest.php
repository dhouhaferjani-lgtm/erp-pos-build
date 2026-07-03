<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

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

final class PartnerCodeUpsertTest extends TestCase
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

    public function test_new_code_creates_partner_with_code_persisted(): void
    {
        $partnerId = $this->service->upsertWithTypeMerge($this->tenant->id, $this->company->id, [
            'code' => 'CUST-001',
            'name' => 'Acme Corp',
            'type' => 'customer',
            'email' => 'acme@example.com',
        ]);

        $partner = Partner::findOrFail($partnerId);

        $this->assertSame('CUST-001', $partner->code);
        $this->assertSame('Acme Corp', $partner->name);
        $this->assertSame(PartnerType::Customer, $partner->type);
    }

    public function test_same_code_updates_existing_partner_even_when_name_changes(): void
    {
        $firstId = $this->service->upsertWithTypeMerge($this->tenant->id, $this->company->id, [
            'code' => 'CUST-001',
            'name' => 'Acme Corp',
            'type' => 'customer',
            'email' => 'old@example.com',
        ]);

        $secondId = $this->service->upsertWithTypeMerge($this->tenant->id, $this->company->id, [
            'code' => 'CUST-001',
            'name' => 'Acme France',
            'type' => 'customer',
            'email' => 'new@example.com',
        ]);

        $partner = Partner::findOrFail($firstId);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, Partner::where('company_id', $this->company->id)->count());
        $this->assertSame('Acme France', $partner->refresh()->name);
        $this->assertSame('new@example.com', $partner->email);
    }

    public function test_vat_match_still_updates_existing_partner_when_code_is_absent(): void
    {
        $existing = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => null,
            'name' => 'Old Name',
            'type' => PartnerType::Customer,
            'vat_number' => 'FR12345678901',
        ]);

        $partnerId = $this->service->upsertWithTypeMerge($this->tenant->id, $this->company->id, [
            'name' => 'New Name',
            'type' => 'supplier',
            'vat_number' => 'FR12345678901',
        ]);

        $partner = $existing->refresh();

        $this->assertSame($existing->id, $partnerId);
        $this->assertSame('New Name', $partner->name);
        $this->assertSame(PartnerType::Both, $partner->type);
        $this->assertSame(1, Partner::where('company_id', $this->company->id)->count());
    }

    public function test_blank_code_falls_back_to_vat_or_name_matching(): void
    {
        $existing = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => null,
            'name' => 'Blank Code Partner',
            'type' => PartnerType::Customer,
            'vat_number' => 'FR00000000001',
        ]);

        $partnerId = $this->service->upsertWithTypeMerge($this->tenant->id, $this->company->id, [
            'code' => '   ',
            'name' => 'Blank Code Partner Updated',
            'type' => 'customer',
            'vat_number' => 'FR00000000001',
        ]);

        $partner = $existing->refresh();

        $this->assertSame($existing->id, $partnerId);
        $this->assertNull($partner->code);
        $this->assertSame('Blank Code Partner Updated', $partner->name);
        $this->assertSame(1, Partner::where('company_id', $this->company->id)->count());
    }
}
