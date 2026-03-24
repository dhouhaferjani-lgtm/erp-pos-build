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
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\IngredientTranslation;
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

class IngredientControllerTest extends TestCase
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
            'slug' => 'test-parapharmacy-ingredients',
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
            'email' => 'user@ingredients.test',
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
    public function it_can_list_ingredients(): void
    {
        $ingredient = Ingredient::create([
            'slug' => 'vitamin-c',
            'is_allergen' => false,
            'regulatory_status' => 'approved',
        ]);

        IngredientTranslation::create([
            'ingredient_id' => $ingredient->id,
            'locale' => 'en',
            'name' => 'Vitamin C',
            'description' => 'Ascorbic acid',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/parapharmacy/ingredients');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta',
            ]);

        // Verify the ingredient is in the response
        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    /** @test */
    public function it_can_create_ingredient(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/parapharmacy/ingredients', [
                'slug' => 'hyaluronic-acid',
                'cas_number' => '9004-61-9',
                'is_allergen' => false,
                'regulatory_status' => 'approved',
                'notes' => 'Common moisturizing agent',
                'translations' => [
                    [
                        'locale' => 'en',
                        'name' => 'Hyaluronic Acid',
                        'description' => 'A naturally occurring substance',
                    ],
                    [
                        'locale' => 'fr',
                        'name' => 'Acide Hyaluronique',
                        'description' => 'Une substance naturelle',
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'hyaluronic-acid');

        $this->assertDatabaseHas('ingredients', [
            'slug' => 'hyaluronic-acid',
            'cas_number' => '9004-61-9',
            'is_allergen' => false,
            'regulatory_status' => 'approved',
        ]);

        $this->assertDatabaseHas('ingredient_translations', [
            'locale' => 'en',
            'name' => 'Hyaluronic Acid',
        ]);

        $this->assertDatabaseHas('ingredient_translations', [
            'locale' => 'fr',
            'name' => 'Acide Hyaluronique',
        ]);
    }

    /** @test */
    public function it_can_update_ingredient(): void
    {
        $ingredient = Ingredient::create([
            'slug' => 'retinol',
            'is_allergen' => false,
            'regulatory_status' => 'approved',
        ]);

        $translation = IngredientTranslation::create([
            'ingredient_id' => $ingredient->id,
            'locale' => 'en',
            'name' => 'Retinol',
            'description' => 'Vitamin A derivative',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/parapharmacy/ingredients/{$ingredient->id}", [
                'regulatory_status' => 'restricted',
                'translations' => [
                    [
                        'id' => $translation->id,
                        'locale' => 'en',
                        'name' => 'Retinol (Vitamin A1)',
                        'description' => 'Updated description',
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.slug', 'retinol');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'regulatory_status' => 'restricted',
        ]);

        $this->assertDatabaseHas('ingredient_translations', [
            'id' => $translation->id,
            'name' => 'Retinol (Vitamin A1)',
            'description' => 'Updated description',
        ]);
    }

    /** @test */
    public function it_can_delete_ingredient(): void
    {
        $ingredient = Ingredient::create([
            'slug' => 'niacinamide',
            'is_allergen' => false,
            'regulatory_status' => 'approved',
        ]);

        IngredientTranslation::create([
            'ingredient_id' => $ingredient->id,
            'locale' => 'en',
            'name' => 'Niacinamide',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/ingredients/{$ingredient->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('ingredients', [
            'id' => $ingredient->id,
        ]);
    }

    /** @test */
    public function it_prevents_deletion_of_in_use_ingredient(): void
    {
        $ingredient = Ingredient::create([
            'slug' => 'salicylic-acid',
            'is_allergen' => false,
            'regulatory_status' => 'approved',
        ]);

        IngredientTranslation::create([
            'ingredient_id' => $ingredient->id,
            'locale' => 'en',
            'name' => 'Salicylic Acid',
        ]);

        // Create a real product and metadata to satisfy FK constraints
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        DB::table('product_ingredient')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/parapharmacy/ingredients/{$ingredient->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'INGREDIENT_IN_USE');

        // Ingredient should still exist
        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
        ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_ingredients(): void
    {
        $response = $this->getJson('/api/v1/parapharmacy/ingredients');

        $response->assertStatus(401);
    }

    /** @test */
    public function user_without_settings_manage_permission_cannot_create_ingredient(): void
    {
        $limitedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Limited User',
            'email' => 'limited@ingredients.test',
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
            ->postJson('/api/v1/parapharmacy/ingredients', [
                'slug' => 'test-ingredient',
                'is_allergen' => false,
                'regulatory_status' => 'approved',
                'translations' => [
                    ['locale' => 'en', 'name' => 'Test'],
                ],
            ]);

        $response->assertStatus(403);
    }
}
