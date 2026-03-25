<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartnerTaxStatusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private User $unauthorizedUser;

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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->unauthorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Perms User',
            'email' => 'noperms@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_get_tax_status_for_registered_partner(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Registered Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/tax-status");

        $response->assertOk()
            ->assertJsonPath('data.tax_status', 'REGISTERED')
            ->assertJsonPath('data.tax_status_label', 'VAT Registered')
            ->assertJsonPath('data.has_valid_exemption', false)
            ->assertJsonPath('data.warnings', []);
    }

    public function test_tax_status_with_valid_exemption_certificate(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Exempt Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_reason' => 'Diplomatic immunity',
            'tax_exemption_certificate_media_id' => Str::uuid()->toString(),
            'tax_exemption_valid_until' => now()->addYear(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/tax-status");

        $response->assertOk()
            ->assertJsonPath('data.tax_status', 'EXEMPT')
            ->assertJsonPath('data.tax_status_label', 'Tax Exempt')
            ->assertJsonPath('data.has_valid_exemption', true)
            ->assertJsonPath('data.exemption_reason', 'Diplomatic immunity')
            ->assertJsonPath('data.exemption_valid_until', $partner->tax_exemption_valid_until->format('Y-m-d'));
    }

    public function test_tax_status_warnings_for_missing_and_expired_certificate(): void
    {
        // Missing certificate
        $partnerMissing = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Missing Cert Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_reason' => 'Charity',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partnerMissing->id}/tax-status");

        $response->assertOk()
            ->assertJsonPath('data.has_valid_exemption', false);

        $warnings = $response->json('data.warnings');
        $this->assertNotEmpty($warnings);
        $this->assertEquals('missing_certificate', $warnings[0]['type']);
        $this->assertEquals('error', $warnings[0]['severity']);

        // Expired certificate
        $partnerExpired = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Expired Cert Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_certificate_media_id' => Str::uuid()->toString(),
            'tax_exemption_valid_until' => now()->subMonth(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partnerExpired->id}/tax-status");

        $response->assertOk()
            ->assertJsonPath('data.has_valid_exemption', false);

        $warnings = $response->json('data.warnings');
        $this->assertNotEmpty($warnings);
        $this->assertEquals('expired_certificate', $warnings[0]['type']);
        $this->assertEquals('error', $warnings[0]['severity']);

        // Expiring soon (within 30 days)
        $partnerExpiring = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Expiring Soon Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_certificate_media_id' => Str::uuid()->toString(),
            'tax_exemption_valid_until' => now()->addDays(15),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partnerExpiring->id}/tax-status");

        $response->assertOk()
            ->assertJsonPath('data.has_valid_exemption', true);

        $warnings = $response->json('data.warnings');
        $this->assertNotEmpty($warnings);
        $this->assertEquals('expiring_soon', $warnings[0]['type']);
        $this->assertEquals('warning', $warnings[0]['severity']);
    }

    public function test_no_expiring_soon_warning_for_certificate_far_in_future(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Far Future Cert Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'tax_exemption_certificate_media_id' => Str::uuid()->toString(),
            'tax_exemption_valid_until' => now()->addDays(275),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/tax-status");

        $response->assertOk()
            ->assertJsonPath('data.has_valid_exemption', true);

        $warnings = $response->json('data.warnings');
        $this->assertEmpty($warnings, 'Certificate 275 days away should not trigger expiring_soon warning');
    }

    public function test_tax_status_returns_404_for_nonexistent_partner(): void
    {
        $fakeId = Str::uuid()->toString();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$fakeId}/tax-status");

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'PARTNER_NOT_FOUND');
    }

    public function test_tax_status_requires_partners_view_permission(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Auth Test Partner',
            'type' => PartnerType::Customer,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $response = $this->actingAs($this->unauthorizedUser, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/tax-status");

        $response->assertForbidden();
    }
}
