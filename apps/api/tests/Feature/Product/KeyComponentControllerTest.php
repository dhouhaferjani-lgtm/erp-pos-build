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
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\KeyComponentTranslation;
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

class KeyComponentControllerTest extends TestCase
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
            'slug' => 'test-parapharmacy-kc',
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
            'email' => 'user@keycomponents.test',
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

    /** @test */
    public function it_can_list_key_components(): void
    {
        $component = KeyComponent::create([
            'slug' => 'omega-3-fatty-acids',
            'is_allergen' => false,
        ]);

        KeyComponentTranslation::create([
            'component_id' => $component->id,
            'locale' => 'en',
            'name' => 'Omega-3 Fatty Acids',
            'description' => 'Essential fatty acids',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/parapharmacy/key-components');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta',
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    /** @test */
    public function it_can_create_key_component(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/parapharmacy/key-components', [
                'slug' => 'collagen-peptides',
                'is_allergen' => false,
                'translations' => [
                    [
                        'locale' => 'en',
                        'name' => 'Collagen Peptides',
                        'description' => 'Hydrolyzed collagen for skin health',
                    ],
                    [
                        'locale' => 'fr',
                        'name' => 'Peptides de Collagene',
                        'description' => 'Collagene hydrolyse pour la sante de la peau',
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'collagen-peptides');

        $this->assertDatabaseHas('product_key_components', [
            'slug' => 'collagen-peptides',
            'is_allergen' => false,
        ]);

        $this->assertDatabaseHas('key_component_translations', [
            'locale' => 'en',
            'name' => 'Collagen Peptides',
        ]);

        $this->assertDatabaseHas('key_component_translations', [
            'locale' => 'fr',
            'name' => 'Peptides de Collagene',
        ]);
    }

    /** @test */
    public function it_can_update_key_component(): void
    {
        $component = KeyComponent::create([
            'slug' => 'probiotics',
            'is_allergen' => false,
        ]);

        $translation = KeyComponentTranslation::create([
            'component_id' => $component->id,
            'locale' => 'en',
            'name' => 'Probiotics',
            'description' => 'Beneficial bacteria',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/parapharmacy/key-components/{$component->id}", [
                'is_allergen' => true,
                'translations' => [
                    [
                        'id' => $translation->id,
                        'locale' => 'en',
                        'name' => 'Probiotics (Dairy-derived)',
                        'description' => 'Contains dairy allergens',
                    ],
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('product_key_components', [
            'id' => $component->id,
            'is_allergen' => true,
        ]);

        $this->assertDatabaseHas('key_component_translations', [
            'id' => $translation->id,
            'name' => 'Probiotics (Dairy-derived)',
        ]);
    }

    /** @test */
    public function it_can_delete_key_component(): void
    {
        $component = KeyComponent::create([
            'slug' => 'coenzyme-q10',
            'is_allergen' => false,
        ]);

        KeyComponentTranslation::create([
            'component_id' => $component->id,
            'locale' => 'en',
            'name' => 'Coenzyme Q10',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/key-components/{$component->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('product_key_components', [
            'id' => $component->id,
        ]);
    }

    /** @test */
    public function it_prevents_deletion_of_in_use_key_component(): void
    {
        $component = KeyComponent::create([
            'slug' => 'zinc',
            'is_allergen' => false,
        ]);

        KeyComponentTranslation::create([
            'component_id' => $component->id,
            'locale' => 'en',
            'name' => 'Zinc',
        ]);

        // Create a real product and metadata to satisfy FK constraints
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        DB::table('key_component_product')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $product->id,
            'component_id' => $component->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/key-components/{$component->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'KEY_COMPONENT_IN_USE');

        $this->assertDatabaseHas('product_key_components', [
            'id' => $component->id,
        ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_key_components(): void
    {
        $response = $this->getJson('/api/v1/parapharmacy/key-components');

        $response->assertStatus(401);
    }

    /** @test */
    public function user_without_settings_manage_permission_cannot_create_key_component(): void
    {
        $limitedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Limited User',
            'email' => 'limited@keycomponents.test',
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
            ->postJson('/api/v1/parapharmacy/key-components', [
                'slug' => 'test-component',
                'is_allergen' => false,
                'translations' => [
                    ['locale' => 'en', 'name' => 'Test'],
                ],
            ]);

        $response->assertStatus(403);
    }
}
