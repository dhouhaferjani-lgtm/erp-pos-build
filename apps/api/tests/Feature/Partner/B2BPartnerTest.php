<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class B2BPartnerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-b2b-tenant',
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
            'name' => 'Test User',
            'email' => 'b2b-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // ─── Create with B2B fields ───────────────────────────────────────

    public function test_can_create_b2b_partner_with_all_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'ACME B2B Corp',
                'type' => 'customer',
                'customer_category' => 'business',
                'company_legal_name' => 'ACME Corporation SAS',
                'business_registration_number' => '73282932000074',
                'payment_terms' => 'net_30',
                'credit_limit' => '50000.0000',
                'discount_percentage' => '5.00',
                'invoice_consolidation' => true,
                'consolidation_frequency' => 'monthly',
                'country_code' => 'FR',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'ACME B2B Corp')
            ->assertJsonPath('data.customer_category', 'business')
            ->assertJsonPath('data.company_legal_name', 'ACME Corporation SAS')
            ->assertJsonPath('data.business_registration_number', '73282932000074')
            ->assertJsonPath('data.payment_terms', 'net_30')
            ->assertJsonPath('data.invoice_consolidation', true)
            ->assertJsonPath('data.consolidation_frequency', 'monthly');

        $this->assertDatabaseHas('partners', [
            'name' => 'ACME B2B Corp',
            'company_legal_name' => 'ACME Corporation SAS',
            'payment_terms' => 'net_30',
            'invoice_consolidation' => true,
            'consolidation_frequency' => 'monthly',
        ]);
    }

    public function test_can_create_individual_partner_without_b2b_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'John Doe',
                'type' => 'customer',
                'customer_category' => 'individual',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_category', 'individual')
            ->assertJsonPath('data.company_legal_name', null)
            ->assertJsonPath('data.payment_terms', null)
            ->assertJsonPath('data.invoice_consolidation', false);
    }

    public function test_b2b_fields_are_nullable_for_business_customers(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Minimal B2B',
                'type' => 'customer',
                'customer_category' => 'business',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_category', 'business')
            ->assertJsonPath('data.company_legal_name', null)
            ->assertJsonPath('data.credit_limit', null);
    }

    // ─── Validation ───────────────────────────────────────────────────

    public function test_payment_terms_must_be_valid_enum(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Bad Terms Partner',
                'type' => 'customer',
                'payment_terms' => 'invalid_term',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['payment_terms']]]);
    }

    public function test_consolidation_frequency_must_be_valid_enum(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Bad Freq Partner',
                'type' => 'customer',
                'invoice_consolidation' => true,
                'consolidation_frequency' => 'daily',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['consolidation_frequency']]]);
    }

    public function test_custom_payment_terms_requires_days(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Custom Terms Partner',
                'type' => 'customer',
                'payment_terms' => 'custom',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['payment_terms_days']]]);
    }

    public function test_custom_payment_terms_with_days_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Custom Terms Partner',
                'type' => 'customer',
                'payment_terms' => 'custom',
                'payment_terms_days' => 45,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_terms', 'custom')
            ->assertJsonPath('data.payment_terms_days', 45);
    }

    public function test_credit_limit_must_be_non_negative(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Negative Limit Partner',
                'type' => 'customer',
                'credit_limit' => '-1000',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['credit_limit']]]);
    }

    public function test_discount_percentage_max_100(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Over Discount Partner',
                'type' => 'customer',
                'discount_percentage' => '150',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['discount_percentage']]]);
    }

    // ─── Update B2B fields ────────────────────────────────────────────

    public function test_can_update_partner_b2b_fields(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Update Me Corp',
            'type' => 'customer',
            'customer_category' => 'business',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$partner->id}", [
                'company_legal_name' => 'Updated Legal Name SAS',
                'payment_terms' => 'net_60',
                'credit_limit' => '25000.0000',
                'discount_percentage' => '10.00',
                'invoice_consolidation' => true,
                'consolidation_frequency' => 'weekly',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.company_legal_name', 'Updated Legal Name SAS')
            ->assertJsonPath('data.payment_terms', 'net_60')
            ->assertJsonPath('data.invoice_consolidation', true)
            ->assertJsonPath('data.consolidation_frequency', 'weekly');
    }

    public function test_can_clear_b2b_fields_on_update(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Clear B2B Corp',
            'type' => 'customer',
            'customer_category' => 'business',
            'company_legal_name' => 'Old Legal Name',
            'payment_terms' => 'net_30',
            'credit_limit' => '10000.0000',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$partner->id}", [
                'company_legal_name' => null,
                'payment_terms' => null,
                'credit_limit' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.company_legal_name', null)
            ->assertJsonPath('data.payment_terms', null)
            ->assertJsonPath('data.credit_limit', null);
    }

    // ─── Validate Tax ID endpoint ─────────────────────────────────────

    public function test_validate_tax_id_returns_valid_for_correct_siret(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Valid SIRET Corp',
            'type' => 'customer',
            'country_code' => 'FR',
            'business_registration_number' => '73282932000074',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/partners/{$partner->id}/validate-tax-id");

        $response->assertOk()
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.format', 'SIRET');
    }

    public function test_validate_tax_id_returns_invalid_for_bad_siret(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Invalid SIRET Corp',
            'type' => 'customer',
            'country_code' => 'FR',
            'business_registration_number' => '12345678901234',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/partners/{$partner->id}/validate-tax-id");

        $response->assertOk()
            ->assertJsonPath('data.is_valid', false)
            ->assertJsonPath('data.format', 'SIRET');
    }

    public function test_validate_tax_id_fails_without_registration_number(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'No Reg Number Corp',
            'type' => 'customer',
            'country_code' => 'FR',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/partners/{$partner->id}/validate-tax-id");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'MISSING_DATA');
    }

    public function test_validate_tax_id_returns_404_for_nonexistent_partner(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners/00000000-0000-0000-0000-000000000000/validate-tax-id');

        $response->assertNotFound();
    }

    // ─── Show returns B2B fields ──────────────────────────────────────

    public function test_show_returns_b2b_fields(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Show B2B Corp',
            'type' => 'customer',
            'customer_category' => 'business',
            'company_legal_name' => 'Show Legal Name',
            'payment_terms' => 'net_90',
            'credit_limit' => '100000.0000',
            'discount_percentage' => '15.50',
            'invoice_consolidation' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}");

        $response->assertOk()
            ->assertJsonPath('data.company_legal_name', 'Show Legal Name')
            ->assertJsonPath('data.payment_terms', 'net_90')
            ->assertJsonPath('data.credit_limit', '100000.0000')
            ->assertJsonPath('data.discount_percentage', '15.50')
            ->assertJsonPath('data.invoice_consolidation', false)
            ->assertJsonPath('data.consolidation_frequency', null);
    }
}
