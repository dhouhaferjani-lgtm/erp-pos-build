<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\HealthClaimTranslation;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HealthClaimControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Parapharmacy',
            'slug' => 'test-parapharmacy-hc',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
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
            'email' => 'user@healthclaims.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_list_health_claims(): void
    {
        $claim = HealthClaim::create([
            'claim_type' => 'function',
            'slug' => 'immune-support',
            'regulatory_status' => 'approved',
            'requires_disclaimer' => false,
        ]);

        HealthClaimTranslation::create([
            'health_claim_id' => $claim->id,
            'locale' => 'en',
            'claim' => 'Supports immune system function',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/parapharmacy/health-claims');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta',
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    public function test_create_health_claim(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/parapharmacy/health-claims', [
                'claim_type' => 'reduction_of_disease_risk',
                'slug' => 'bone-health',
                'regulatory_status' => 'approved',
                'efsa_reference' => 'EFSA-2024-001',
                'requires_disclaimer' => true,
                'translations' => [
                    [
                        'locale' => 'en',
                        'claim' => 'Calcium contributes to the maintenance of normal bones',
                        'disclaimer_text' => 'This claim has been evaluated by EFSA',
                    ],
                    [
                        'locale' => 'fr',
                        'claim' => 'Le calcium contribue au maintien des os normaux',
                        'disclaimer_text' => 'Cette allegation a ete evaluee par EFSA',
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'bone-health');

        $this->assertDatabaseHas('health_claims', [
            'slug' => 'bone-health',
            'claim_type' => 'reduction_of_disease_risk',
            'regulatory_status' => 'approved',
            'efsa_reference' => 'EFSA-2024-001',
            'requires_disclaimer' => true,
        ]);

        $this->assertDatabaseHas('health_claim_translations', [
            'locale' => 'en',
            'claim' => 'Calcium contributes to the maintenance of normal bones',
        ]);
    }

    public function test_update_health_claim(): void
    {
        $claim = HealthClaim::create([
            'claim_type' => 'function',
            'slug' => 'eye-health',
            'regulatory_status' => 'pending',
            'requires_disclaimer' => false,
        ]);

        $translation = HealthClaimTranslation::create([
            'health_claim_id' => $claim->id,
            'locale' => 'en',
            'claim' => 'Supports eye health',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/parapharmacy/health-claims/{$claim->id}", [
                'regulatory_status' => 'approved',
                'requires_disclaimer' => true,
                'translations' => [
                    [
                        'id' => $translation->id,
                        'locale' => 'en',
                        'claim' => 'Lutein contributes to the maintenance of normal vision',
                        'disclaimer_text' => 'EFSA approved claim',
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('health_claims', [
            'id' => $claim->id,
            'regulatory_status' => 'approved',
            'requires_disclaimer' => true,
        ]);

        $this->assertDatabaseHas('health_claim_translations', [
            'id' => $translation->id,
            'claim' => 'Lutein contributes to the maintenance of normal vision',
        ]);
    }

    public function test_delete_health_claim(): void
    {
        $claim = HealthClaim::create([
            'claim_type' => 'function',
            'slug' => 'energy-metabolism',
            'regulatory_status' => 'rejected',
            'requires_disclaimer' => false,
        ]);

        HealthClaimTranslation::create([
            'health_claim_id' => $claim->id,
            'locale' => 'en',
            'claim' => 'Boosts energy',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/health-claims/{$claim->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('health_claims', [
            'id' => $claim->id,
        ]);
    }

    public function test_prevents_deletion_of_in_use_health_claim(): void
    {
        $claim = HealthClaim::create([
            'claim_type' => 'function',
            'slug' => 'heart-health',
            'regulatory_status' => 'approved',
            'requires_disclaimer' => true,
        ]);

        HealthClaimTranslation::create([
            'health_claim_id' => $claim->id,
            'locale' => 'en',
            'claim' => 'Supports cardiovascular health',
        ]);

        // Create a real product and metadata to satisfy FK constraints
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        DB::table('health_claim_product')->insert([
            'id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'health_claim_id' => $claim->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/health-claims/{$claim->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'HEALTH_CLAIM_IN_USE');

        $this->assertDatabaseHas('health_claims', [
            'id' => $claim->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_health_claims(): void
    {
        $response = $this->getJson('/api/v1/parapharmacy/health-claims');

        $response->assertStatus(401);
    }

    public function test_user_without_settings_manage_permission_cannot_create_health_claim(): void
    {
        $limitedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Limited User',
            'email' => 'limited@healthclaims.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $limitedUser->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $limitedUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Cashier,
        ]);

        $response = $this->actingAs($limitedUser, 'sanctum')
            ->postJson('/api/v1/parapharmacy/health-claims', [
                'claim_type' => 'function',
                'slug' => 'test-claim',
                'regulatory_status' => 'approved',
                'requires_disclaimer' => false,
                'translations' => [
                    ['locale' => 'en', 'claim' => 'Test claim'],
                ],
            ]);

        $response->assertStatus(403);
    }
}
