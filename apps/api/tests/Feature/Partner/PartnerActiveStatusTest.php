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

class PartnerActiveStatusTest extends TestCase
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
    }

    public function test_can_create_partner_with_is_active_false(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Inactive Partner',
                'type' => 'customer',
                'is_active' => false,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('partners', [
            'name' => 'Inactive Partner',
            'is_active' => false,
        ]);
    }

    public function test_partner_defaults_to_active_when_not_specified(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Default Active Partner',
                'type' => 'customer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('partners', [
            'name' => 'Default Active Partner',
            'is_active' => true,
        ]);
    }

    public function test_can_deactivate_partner_via_update(): void
    {
        // Create an active partner first
        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'Soon Inactive',
                'type' => 'customer',
            ]);

        $createResponse->assertCreated();
        $partnerId = $createResponse->json('data.id');

        // Deactivate via update
        $updateResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$partnerId}", [
                'is_active' => false,
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('partners', [
            'id' => $partnerId,
            'is_active' => false,
        ]);
    }

    public function test_is_active_filter_returns_only_active_partners(): void
    {
        // Create one active and one inactive partner
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Active Partner',
            'type' => 'customer',
            'is_active' => true,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Inactive Partner',
            'type' => 'customer',
            'is_active' => false,
        ]);

        // Filter for active only
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?is_active=true');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Active Partner', $names);
        $this->assertNotContains('Inactive Partner', $names);
    }

    public function test_is_active_filter_returns_only_inactive_partners(): void
    {
        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Active Partner',
            'type' => 'customer',
            'is_active' => true,
        ]);

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Inactive Partner',
            'type' => 'customer',
            'is_active' => false,
        ]);

        // Filter for inactive only
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/partners?is_active=false');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Inactive Partner', $names);
        $this->assertNotContains('Active Partner', $names);
    }
}
