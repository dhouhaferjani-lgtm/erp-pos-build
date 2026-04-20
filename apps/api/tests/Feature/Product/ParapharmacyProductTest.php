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
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class ParapharmacyProductTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create parapharmacy tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Parapharmacy',
            'slug' => 'test-parapharmacy',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Parapharmacy Company',
            'legal_name' => 'Test Parapharmacy Company LLC',
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
            'email' => 'user@parapharmacy.test',
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
    public function it_can_create_product_with_parapharmacy_metadata_via_api(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Vitamin C 1000mg',
                'sku' => 'VIT-C-1000',
                'type' => 'part',
                'sale_price' => 15.99,
                'parapharmacy_metadata' => [
                    'category' => 'supplement',
                    'dosage_form' => 'tablet',
                    'active_ingredients' => [
                        ['name' => 'Ascorbic Acid', 'concentration' => '1000mg'],
                        ['name' => 'Citrus Bioflavonoids', 'concentration' => '100mg'],
                    ],
                    'usage_instructions' => 'Take 1 tablet daily with food.',
                    'warnings' => 'Consult a healthcare professional if pregnant or nursing.',
                    'minimum_age' => 18,
                    'age_restriction' => 'adult_only',
                    'requires_consultation' => false,
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Vitamin C 1000mg')
            ->assertJsonPath('data.parapharmacy_metadata.category', 'supplement')
            ->assertJsonPath('data.parapharmacy_metadata.dosage_form', 'tablet')
            ->assertJsonPath('data.parapharmacy_metadata.usage_instructions', 'Take 1 tablet daily with food.');

        // Note: active_ingredients are now stored via the Ingredient relation (managed via dedicated endpoints),
        // not as a JSON column. The parapharmacy_metadata create flow does not sync ingredients.

        $this->assertDatabaseHas('products', [
            'name' => 'Vitamin C 1000mg',
            'sku' => 'VIT-C-1000',
        ]);

        $this->assertDatabaseHas('parapharmacy_product_metadata', [
            'category' => 'supplement',
            'dosage_form' => 'tablet',
            'requires_consultation' => false,
        ]);
    }

    /** @test */
    public function it_can_update_product_parapharmacy_metadata_via_api(): void
    {
        // Create product with metadata
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Omega-3 Fish Oil',
            'sku' => 'OMEGA-3',
        ]);

        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::Supplement,
            'dosage_form' => DosageForm::Softgel,
        ]);

        // Update metadata
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'parapharmacy_metadata' => [
                    'category' => 'supplement',
                    'dosage_form' => 'capsule',
                    'active_ingredients' => [
                        ['name' => 'EPA', 'concentration' => '180mg'],
                        ['name' => 'DHA', 'concentration' => '120mg'],
                    ],
                    'warnings' => 'May thin blood. Consult doctor if on blood thinners.',
                    'requires_consultation' => true,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.parapharmacy_metadata.dosage_form', 'capsule')
            ->assertJsonPath('data.parapharmacy_metadata.requires_consultation', true);

        // Note: active_ingredients are now managed via the Ingredient relation (dedicated endpoints),
        // not as a JSON column on parapharmacy_product_metadata.

        $this->assertDatabaseHas('parapharmacy_product_metadata', [
            'product_id' => $product->id,
            'dosage_form' => 'capsule',
            'requires_consultation' => true,
        ]);
    }

    /** @test */
    public function it_loads_parapharmacy_metadata_when_fetching_single_product(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Collagen Powder',
            'sku' => 'COL-PWD',
        ]);

        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::Supplement,
            'dosage_form' => DosageForm::Powder,
            'usage_instructions' => 'Mix 1 scoop with water daily.',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.parapharmacy_metadata.category', 'supplement')
            ->assertJsonPath('data.parapharmacy_metadata.dosage_form', 'powder')
            ->assertJsonPath('data.parapharmacy_metadata.usage_instructions', 'Mix 1 scoop with water daily.');
    }

    /** @test */
    public function it_loads_parapharmacy_metadata_when_listing_products(): void
    {
        $product1 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product 1',
            'sku' => 'PROD-1',
        ]);

        $metadata1 = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product1->id,
            'category' => ParapharmacyCategory::Supplement,
        ]);

        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product 2',
            'sku' => 'PROD-2',
        ]);

        $metadata2 = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product2->id,
            'category' => ParapharmacyCategory::Cosmetic,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.parapharmacy_metadata.category', 'supplement')
            ->assertJsonPath('data.1.parapharmacy_metadata.category', 'cosmetic');
    }

    /** @test */
    public function it_cascades_delete_metadata_when_product_is_deleted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TEST-123',
        ]);

        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
            'category' => ParapharmacyCategory::Supplement,
        ]);

        $metadataId = $metadata->id;

        // Delete the product
        $product->forceDelete();

        // Metadata should be cascade deleted
        $this->assertDatabaseMissing('parapharmacy_product_metadata', [
            'id' => $metadataId,
        ]);
    }

    /** @test */
    public function it_does_not_load_parapharmacy_metadata_for_non_parapharmacy_vertical(): void
    {
        // Create a mechanic tenant
        $mechanicTenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $mechanicCompany = Company::create([
            'tenant_id' => $mechanicTenant->id,
            'name' => 'Test Mechanic Company',
            'legal_name' => 'Test Mechanic Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($mechanicTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $mechanicUser = User::create([
            'tenant_id' => $mechanicTenant->id,
            'name' => 'Mechanic User',
            'email' => 'mechanic@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $mechanicUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $mechanicUser->id,
            'company_id' => $mechanicCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($mechanicCompany->id);

        $product = Product::factory()->create([
            'tenant_id' => $mechanicTenant->id,
            'company_id' => $mechanicCompany->id,
            'name' => 'Brake Pad',
            'sku' => 'BRAKE-001',
        ]);

        $response = $this->actingAs($mechanicUser, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonMissing(['parapharmacy_metadata']);
    }

    /** @test */
    public function parapharmacy_metadata_category_is_required_when_metadata_provided(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'TEST-123',
                'type' => 'part',
                'parapharmacy_metadata' => [
                    'dosage_form' => 'tablet',
                ],
            ]);

        $this->assertApiValidationErrors($response, ['parapharmacy_metadata.category']);
    }

    /** @test */
    public function parapharmacy_metadata_validates_enum_values(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'TEST-123',
                'type' => 'part',
                'parapharmacy_metadata' => [
                    'category' => 'invalid_category',
                    'dosage_form' => 'invalid_form',
                ],
            ]);

        $this->assertApiValidationErrors($response, [
            'parapharmacy_metadata.category',
            'parapharmacy_metadata.dosage_form',
        ]);
    }

    /** @test */
    public function parapharmacy_metadata_validates_active_ingredients_structure(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'TEST-123',
                'type' => 'part',
                'parapharmacy_metadata' => [
                    'category' => 'supplement',
                    'active_ingredients' => [
                        ['concentration' => '100mg'], // Missing name
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['parapharmacy_metadata.active_ingredients.0.name']);
    }
}
