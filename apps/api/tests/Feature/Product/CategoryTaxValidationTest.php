<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Category;
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

class CategoryTaxValidationTest extends TestCase
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
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-tax-cat',
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
            'email' => 'taxcat@example.com',
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
    // Test A: wrong-country config is rejected (create)
    // -------------------------------------------------------------------------

    public function test_create_category_with_wrong_country_tax_config_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Cosmetics',
                'default_tax_configuration_id' => $this->frTaxConfig->id, // FR config, company is TN
            ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['default_tax_configuration_id']);
    }

    // -------------------------------------------------------------------------
    // Test B: matching-country config is accepted (create)
    // -------------------------------------------------------------------------

    public function test_create_category_with_matching_country_tax_config_succeeds(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Cosmetics',
                'default_tax_configuration_id' => $this->tnTaxConfig->id, // TN config, company is TN
                'default_tax_rate' => '19.00',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', [
            'company_id' => $this->company->id,
            'name' => 'Cosmetics',
            'default_tax_configuration_id' => $this->tnTaxConfig->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test C: wrong-country config is rejected on update
    // -------------------------------------------------------------------------

    public function test_update_category_with_wrong_country_tax_config_returns_422(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'default_tax_configuration_id' => $this->frTaxConfig->id, // FR config, company is TN
            ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['default_tax_configuration_id']);
    }

    // -------------------------------------------------------------------------
    // Test D: matching-country config is accepted on update
    // -------------------------------------------------------------------------

    public function test_update_category_with_matching_country_tax_config_succeeds(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'default_tax_configuration_id' => $this->tnTaxConfig->id,
                'default_tax_rate' => '19.00',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'default_tax_configuration_id' => $this->tnTaxConfig->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test E: default_tax_rate exceeds max:100 → 422
    // -------------------------------------------------------------------------

    public function test_create_category_with_tax_rate_over_100_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Over-rated',
                'default_tax_rate' => '150',
            ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['default_tax_rate']);
    }

    // -------------------------------------------------------------------------
    // Test F: default_tax_rate with too many decimal places → 422
    // -------------------------------------------------------------------------

    public function test_create_category_with_tax_rate_too_many_decimals_returns_422(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Precision Error',
                'default_tax_rate' => '10.999',
            ]);

        $response->assertStatus(422);
        $this->assertJsonValidationErrors($response, ['default_tax_rate']);
    }

    // -------------------------------------------------------------------------
    // Test G: null values are accepted (fields are nullable)
    // -------------------------------------------------------------------------

    public function test_create_category_with_null_tax_fields_succeeds(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'No Tax Category',
                'default_tax_rate' => null,
                'default_tax_configuration_id' => null,
            ]);

        $response->assertCreated();
    }
}
