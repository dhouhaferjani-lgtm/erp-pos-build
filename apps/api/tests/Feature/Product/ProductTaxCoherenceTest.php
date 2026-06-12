<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\FranceTaxConfigurationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaTaxConfigurationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task 11: Country-coherence validation on Product create/update requests.
 *
 * Mirrors the precedent established for composite items (StoreCompositeItemRequest)
 * and categories (Task 10 / CategoryTaxValidationTest). A wrong-country
 * default_tax_configuration_id must be rejected with a 422 at save time.
 */
class ProductTaxCoherenceTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Tenant $tenant;

    private TaxConfiguration $tnTaxConfig;

    private TaxConfiguration $frTaxConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant Tax Product',
            'slug' => 'test-tenant-tax-product',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Company is in Tunisia (TN)
        $this->company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenant->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'taxproduct@example.com',
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

        // countries table is a prerequisite FK for tax_configurations.country_code
        (new CountriesSeeder)->run();

        // Seed TN and FR tax configurations so UUIDs exist in the DB
        $this->seed(TunisiaTaxConfigurationSeeder::class);
        $this->seed(FranceTaxConfigurationSeeder::class);

        $this->tnTaxConfig = TaxConfiguration::where('country_code', 'TN')
            ->where('code', 'TVA_19')
            ->firstOrFail();

        $this->frTaxConfig = TaxConfiguration::where('country_code', 'FR')
            ->where('code', 'TVA_FR_20')
            ->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Test A: wrong-country config is rejected on create
    // -------------------------------------------------------------------------

    public function test_create_product_with_wrong_country_tax_config_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product',
                'sku' => 'PROD-TAX-WRONG-001',
                'default_tax_configuration_id' => $this->frTaxConfig->id, // FR config, company is TN
            ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['default_tax_configuration_id']);
    }

    // -------------------------------------------------------------------------
    // Test B: matching-country config is accepted on create
    // -------------------------------------------------------------------------

    public function test_create_product_with_matching_country_tax_config_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product TN',
                'sku' => 'PROD-TAX-TN-001',
                'default_tax_configuration_id' => $this->tnTaxConfig->id, // TN config, company is TN
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('products', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Product TN',
            'default_tax_configuration_id' => $this->tnTaxConfig->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test C: wrong-country config is rejected on update
    // -------------------------------------------------------------------------

    public function test_update_product_with_wrong_country_tax_config_returns_422(): void
    {
        /** @var Product $product */
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'default_tax_configuration_id' => $this->frTaxConfig->id, // FR config, company is TN
            ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['default_tax_configuration_id']);
    }

    // -------------------------------------------------------------------------
    // Test D: matching-country config is accepted on update
    // -------------------------------------------------------------------------

    public function test_update_product_with_matching_country_tax_config_succeeds(): void
    {
        /** @var Product $product */
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'default_tax_configuration_id' => $this->tnTaxConfig->id, // TN config, company is TN
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'default_tax_configuration_id' => $this->tnTaxConfig->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test E: null is accepted (field is nullable)
    // -------------------------------------------------------------------------

    public function test_create_product_with_null_tax_config_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'No Tax Product',
                'sku' => 'PROD-TAX-NULL-001',
                'default_tax_configuration_id' => null,
            ]);

        $response->assertCreated();
    }
}
