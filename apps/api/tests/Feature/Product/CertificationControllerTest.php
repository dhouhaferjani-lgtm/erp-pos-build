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
use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\CertificationTranslation;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CertificationControllerTest extends TestCase
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
            'slug' => 'test-parapharmacy-certs',
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
            'email' => 'user@certs.test',
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

    public function test_list_certifications(): void
    {
        $cert = Certification::create([
            'type' => 'organic',
            'slug' => 'ecocert-organic',
            'is_active' => true,
            'display_order' => 0,
        ]);

        CertificationTranslation::create([
            'certification_id' => $cert->id,
            'locale' => 'en',
            'name' => 'Ecocert Organic',
            'description' => 'Certified organic by Ecocert',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/parapharmacy/certifications');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta',
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    public function test_create_certification(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/parapharmacy/certifications', [
                'type' => 'gmp',
                'slug' => 'gmp-certified',
                'certifying_body' => 'ISO',
                'is_active' => true,
                'display_order' => 1,
                'translations' => [
                    [
                        'locale' => 'en',
                        'name' => 'GMP Certified',
                        'description' => 'Good Manufacturing Practice',
                    ],
                    [
                        'locale' => 'fr',
                        'name' => 'Certifie BPF',
                        'description' => 'Bonnes Pratiques de Fabrication',
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'gmp-certified');

        $this->assertDatabaseHas('certifications', [
            'slug' => 'gmp-certified',
            'type' => 'gmp',
            'certifying_body' => 'ISO',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $this->assertDatabaseHas('certification_translations', [
            'locale' => 'en',
            'name' => 'GMP Certified',
        ]);
    }

    public function test_update_certification(): void
    {
        $cert = Certification::create([
            'type' => 'bio',
            'slug' => 'bio-cert',
            'is_active' => true,
            'display_order' => 0,
        ]);

        $translation = CertificationTranslation::create([
            'certification_id' => $cert->id,
            'locale' => 'en',
            'name' => 'Bio Certified',
            'description' => 'Biological certification',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/parapharmacy/certifications/{$cert->id}", [
                'is_active' => false,
                'translations' => [
                    [
                        'id' => $translation->id,
                        'locale' => 'en',
                        'name' => 'Bio Certified (Inactive)',
                        'description' => 'No longer active',
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('certifications', [
            'id' => $cert->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('certification_translations', [
            'id' => $translation->id,
            'name' => 'Bio Certified (Inactive)',
        ]);
    }

    public function test_delete_certification(): void
    {
        $cert = Certification::create([
            'type' => 'halal',
            'slug' => 'halal-cert',
            'is_active' => true,
            'display_order' => 0,
        ]);

        CertificationTranslation::create([
            'certification_id' => $cert->id,
            'locale' => 'en',
            'name' => 'Halal',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/certifications/{$cert->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('certifications', [
            'id' => $cert->id,
        ]);
    }

    public function test_prevents_deletion_of_in_use_certification(): void
    {
        $cert = Certification::create([
            'type' => 'vegan',
            'slug' => 'vegan-cert',
            'is_active' => true,
            'display_order' => 0,
        ]);

        CertificationTranslation::create([
            'certification_id' => $cert->id,
            'locale' => 'en',
            'name' => 'Vegan',
        ]);

        // Create a real product and metadata to satisfy FK constraints
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        DB::table('certification_product')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $product->id,
            'certification_id' => $cert->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/certifications/{$cert->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'CERTIFICATION_IN_USE');

        $this->assertDatabaseHas('certifications', [
            'id' => $cert->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_certifications(): void
    {
        $response = $this->getJson('/api/v1/parapharmacy/certifications');

        $response->assertStatus(401);
    }

    public function test_user_without_settings_manage_permission_cannot_create_certification(): void
    {
        $limitedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Limited User',
            'email' => 'limited@certs.test',
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
            ->postJson('/api/v1/parapharmacy/certifications', [
                'type' => 'test',
                'slug' => 'test-cert',
                'is_active' => true,
                'display_order' => 0,
                'translations' => [
                    ['locale' => 'en', 'name' => 'Test'],
                ],
            ]);

        $response->assertStatus(403);
    }
}
