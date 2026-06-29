<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class UpdatePartnerTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

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
            'name' => 'Test User',
            'email' => 'user@example.com',
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

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Partner',
            'type' => PartnerType::Customer,
            'email' => 'original@partner.com',
        ]);
    }

    public function test_can_update_partner_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'name' => 'Updated Partner Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Partner Name');

        $this->assertDatabaseHas('partners', [
            'id' => $this->partner->id,
            'name' => 'Updated Partner Name',
        ]);
    }

    public function test_can_update_partner_email(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'email' => 'updated@partner.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'updated@partner.com');
    }

    public function test_can_update_partner_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'type' => 'supplier',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'supplier');
    }

    public function test_email_format_validation_on_update(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'email' => 'invalid-email',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_vat_number_uniqueness_on_update(): void
    {
        // Create another partner with a VAT number
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Partner',
            'type' => PartnerType::Customer,
            'country_code' => 'FR',
            'vat_number' => 'FR98765432101',
        ]);

        // Try to update the first partner with the same VAT number
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'country_code' => 'FR',
                'vat_number' => 'FR98765432101',
            ]);

        $this->assertApiValidationErrors($response, ['vat_number']);
    }

    public function test_can_update_own_vat_number_to_same_value(): void
    {
        $this->partner->update([
            'country_code' => 'FR',
            'vat_number' => 'FR12345678901',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'vat_number' => 'FR12345678901',
                'name' => 'Updated Name',
            ]);

        $response->assertOk();
    }

    public function test_returns_404_for_nonexistent_partner(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$fakeId}", [
                'name' => 'Updated Name',
            ]);

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_update_partner(): void
    {
        $response = $this->patchJson("/api/v1/partners/{$this->partner->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_update_partner(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertForbidden();
    }

    public function test_returns_404_for_invalid_uuid_on_show(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners/not-a-uuid');

        $response->assertNotFound();
    }

    public function test_returns_404_for_invalid_uuid_on_update(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/v1/partners/not-a-uuid', [
                'name' => 'Updated Name',
            ]);

        $response->assertNotFound();
    }

    public function test_returns_404_for_invalid_uuid_on_delete(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/partners/not-a-uuid');

        $response->assertNotFound();
    }

    public function test_returns_404_for_invalid_uuid_on_contacts(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners/not-a-uuid/contacts');

        $response->assertNotFound();
    }

    public function test_returns_404_for_invalid_uuid_on_tax_status(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners/not-a-uuid/tax-status');

        $response->assertNotFound();
    }

    public function test_returns_404_for_invalid_uuid_on_validate_tax_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners/not-a-uuid/validate-tax-id');

        $response->assertNotFound();
    }

    public function test_parapharmacy_tenant_can_persist_skin_fields(): void
    {
        $this->tenant->update(['vertical' => Vertical::Parapharmacy]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'skin_type' => 'dry',
                'skin_advice_note' => 'Use moisturizer daily',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('partners', [
            'id' => $this->partner->id,
            'skin_type' => 'dry',
            'skin_advice_note' => 'Use moisturizer daily',
        ]);
    }

    public function test_non_parapharmacy_tenant_cannot_persist_skin_fields(): void
    {
        // setUp tenant defaults to the retail vertical.
        $this->assertNotSame(Vertical::Parapharmacy, $this->tenant->fresh()?->vertical);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$this->partner->id}", [
                'name' => 'Updated Name',
                'skin_type' => 'dry',
                'skin_advice_note' => 'Should be ignored',
            ]);

        // The update succeeds (other fields apply); skin fields are silently
        // dropped to mirror the read-path vertical gate.
        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('partners', [
            'id' => $this->partner->id,
            'name' => 'Updated Name',
            'skin_type' => null,
            'skin_advice_note' => null,
        ]);
    }

    public function test_cannot_update_partner_from_another_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherPartner = Partner::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Tenant Partner',
            'type' => PartnerType::Customer,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$otherPartner->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertNotFound();
    }
}
